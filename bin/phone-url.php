<?php

/**
 * phone-url — 查出手机该输入哪个地址才能打开你电脑上的服务
 *
 * 单文件、零依赖（只用 PHP 内置函数；有 curl 扩展时用它做连通性探测，
 * 没有就走 fsockopen，两个都没有就跳过探测）。
 *
 * 用法：
 *   php phone-url.php                        # 默认 80 端口、根路径
 *   php phone-url.php --port=8080 --path=/admin
 *   php phone-url.php --json                 # 给脚本/Agent 用
 *   php phone-url.php --no-check             # 只列地址，不探测
 *   php phone-url.php --prefer=192.168.1.     # 指定优先网段前缀
 *
 * 退出码：0 找到可用地址 / 1 一个局域网地址都没有 / 2 参数错误
 */

declare(strict_types=1);

const HOTSPOT_PREFIXES = [
    '192.168.43.',   // Android 热点（最常见）
    '192.168.42.',   // Android 热点（旧机型）
    '172.20.10.',    // iPhone 个人热点
    '192.168.137.',  // Windows 移动热点
    '192.168.49.',   // 部分 Android
    '10.42.0.',      // Linux NetworkManager 热点
];

const VIRTUAL_HINTS = [
    'vmware', 'virtualbox', 'vethernet', 'hyper-v', 'docker', 'wsl',
    'loopback', 'tunnel', 'tap', 'vpn', 'zerotier', 'tailscale',
    // PPP / 拨号 / 校园网客户端：手机连不上（那是运营商或 VPN 的地址池）
    'ppp', 'wan miniport', 'dtu', 'dial',
];

function usage(): void
{
    fwrite(STDERR, <<<TXT
phone-url — 手机访问本机服务该输入哪个地址

用法: php phone-url.php [选项]

  --port=N         端口，默认 80（80 不写进 URL）
  --path=/x        路径，默认 /（会自动补前导斜杠）
  --scheme=http    协议，默认 http
  --json           输出 JSON（便于脚本消费）
  --no-check       不做连通性探测
  --timeout=3      探测超时秒数，默认 3
  --prefer=PREFIX  优先选哪个网段（可重复，例如 --prefer=192.168.1.）
  --all            把所有地址都当成候选（默认只推荐一个，其余列出来）

退出码: 0 找到地址 / 1 没有局域网地址 / 2 参数错误

TXT);
}

/**
 * @return array{port:int,path:string,scheme:string,json:bool,check:bool,timeout:int,prefer:array<int,string>,all:bool}
 */
function parseArgs(array $argv): array
{
    $out = [
        'port' => 80,
        'path' => '/',
        'scheme' => 'http',
        'json' => false,
        'check' => true,
        'timeout' => 3,
        'prefer' => [],
        'all' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            usage();
            exit(0);
        }
        if ($arg === '--json') {
            $out['json'] = true;
            continue;
        }
        if ($arg === '--no-check') {
            $out['check'] = false;
            continue;
        }
        if ($arg === '--all') {
            $out['all'] = true;
            continue;
        }
        if (preg_match('/^--([a-z]+)=(.*)$/u', $arg, $m) !== 1) {
            fwrite(STDERR, "无法识别的参数: {$arg}\n");
            usage();
            exit(2);
        }
        $key = $m[1];
        $value = $m[2];

        switch ($key) {
            case 'port':
                if (!ctype_digit($value) || (int)$value < 1 || (int)$value > 65535) {
                    fwrite(STDERR, "端口不合法: {$value}\n");
                    exit(2);
                }
                $out['port'] = (int)$value;
                break;
            case 'path':
                $out['path'] = '/' . ltrim($value, '/');
                break;
            case 'scheme':
                $out['scheme'] = $value === 'https' ? 'https' : 'http';
                break;
            case 'timeout':
                if (!ctype_digit($value) || (int)$value < 1) {
                    fwrite(STDERR, "超时必须是正整数秒: {$value}\n");
                    exit(2);
                }
                $out['timeout'] = (int)$value;
                break;
            case 'prefer':
                $out['prefer'][] = $value;
                break;
            default:
                fwrite(STDERR, "未知选项: --{$key}\n");
                usage();
                exit(2);
        }
    }

    return $out;
}

/**
 * 取出本机所有 IPv4 局域网地址。
 *
 * 三个平台各走一条命令，解析失败就返回空数组（而不是抛异常），
 * 因为「没网」本身就是一种答案。
 *
 * @return array<int, array{ip:string, iface:string, virtual:bool}>
 */
function localAddresses(): array
{
    $rows = [];

    if (stripos(PHP_OS_FAMILY, 'windows') !== false) {
        // 纯 ipconfig 会把中文适配器名输出成 ????（控制台代码页 437 时尤其明显），
        // 所以先用 PowerShell 取一份带描述的名称；PPP / VPN 这类不出现在 Get-NetAdapter
        // 里的接口再用 ipconfig 补上（ASCII 前缀 "PPP adapter" 足以判定虚拟网卡）。
        $rows = windowsAdapters();
        foreach (parseIpconfig(toUtf8((string)@shell_exec('ipconfig 2>&1'))) as $row) {
            $rows[] = $row;
        }
    } elseif (is_executable('/sbin/ip') || is_executable('/usr/sbin/ip') || is_executable('/usr/bin/ip')) {
        $rows = parseIpAddr((string)@shell_exec('ip -4 -o addr show 2>/dev/null'));
    }

    if ($rows === []) {
        $rows = parseIfconfig((string)@shell_exec('ifconfig 2>/dev/null'));
    }

    // 过滤：回环、169.254 自动分配、去重
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        $ip = $row['ip'];
        if (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) {
            continue;
        }
        if (isset($seen[$ip])) {
            continue;
        }
        $seen[$ip] = true;
        $out[] = $row;
    }

    return $out;
}

/**
 * Windows：用 PowerShell 列出「适配器名 / 描述 / IPv4」，输出 UTF-8，中文名不会变成 ????。
 *
 * 脚本先写到临时 .ps1 再 -File 执行，避免 cmd.exe 把内层引号吃掉；
 * PowerShell 不可用时返回空数组，调用方会退回 ipconfig。
 *
 * @return array<int, array{ip:string, iface:string, virtual:bool}>
 */
function windowsAdapters(): array
{
    $ps = <<<'PS'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
Get-NetAdapter -ErrorAction SilentlyContinue | ForEach-Object {
    $alias = $_.Name
    $desc = $_.InterfaceDescription
    Get-NetIPAddress -InterfaceIndex $_.ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue | ForEach-Object {
        $alias + '|' + $desc + '|' + $_.IPAddress
    }
}
PS;
    $tmp = @tempnam(sys_get_temp_dir(), 'phone-url-');
    if ($tmp === false) {
        return [];
    }
    $script = $tmp . '.ps1';
    if (@file_put_contents($script, "\xEF\xBB\xBF" . $ps . "\r\n") === false) {
        @unlink($tmp);

        return [];
    }
    @unlink($tmp);
    $raw = (string)@shell_exec('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' . $script . '" 2>NUL');
    @unlink($script);

    return parseAdapterTable($raw);
}

/**
 * @return array<int, array{ip:string, iface:string, virtual:bool}>
 */
function parseAdapterTable(string $raw): array
{
    $rows = [];
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        $line = trim($line);
        if (substr_count($line, '|') !== 2) {
            continue;
        }
        [$alias, $desc, $ip] = explode('|', $line);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            continue;
        }
        $label = trim($alias) . ($desc !== '' ? ' (' . trim($desc) . ')' : '');
        $rows[] = ['ip' => trim($ip), 'iface' => $label, 'virtual' => isVirtual($label)];
    }

    return $rows;
}

/**
 * @return array<int, array{ip:string, iface:string, virtual:bool}>
 */
function parseIpconfig(string $raw): array
{
    $rows = [];
    $iface = '';
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        $line = trim($line);

        // 适配器标题：中英文 Windows 都以「:」结尾
        if (preg_match('/^(.*?(?:适配器|adapter)\s.*?):$/iu', $line, $m) === 1) {
            $iface = trim($m[1]);
            continue;
        }
        if (preg_match('/(?:IPv4|IPv4 地址)[^:]*:\s*([0-9.]+)/u', $line, $m) === 1) {
            $name = $iface !== '' ? $iface : 'unknown';
            if (str_contains($name, '?')) {
                // 控制台代码页拿不到中文时只剩 ????，别把它原样丢给用户
                $name = trim((string)preg_replace('/\?+/', '', $name));
                $name = ($name !== '' ? $name : 'adapter') . ' [name unreadable in this code page]';
            }
            $rows[] = ['ip' => $m[1], 'iface' => $name, 'virtual' => isVirtual($iface)];
        }
    }

    return $rows;
}

/**
 * @return array<int, array{ip:string, iface:string, virtual:bool}>
 */
function parseIpAddr(string $raw): array
{
    $rows = [];
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        // 2: wlan0    inet 192.168.1.7/24 brd ... scope global wlan0
        if (preg_match('/^\d+:\s+(\S+)\s+inet\s+([0-9.]+)\//u', trim($line), $m) === 1) {
            $rows[] = ['ip' => $m[2], 'iface' => $m[1], 'virtual' => isVirtual($m[1])];
        }
    }

    return $rows;
}

/**
 * @return array<int, array{ip:string, iface:string, virtual:bool}>
 */
function parseIfconfig(string $raw): array
{
    $rows = [];
    $iface = '';
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        if (preg_match('/^([a-z0-9_.:-]+):?\s/u', $line, $m) === 1 && !preg_match('/inet /u', $line)) {
            $iface = rtrim($m[1], ':');
        }
        if (preg_match('/inet\s+(?:addr:)?([0-9.]+)/u', $line, $m) === 1) {
            $rows[] = ['ip' => $m[1], 'iface' => $iface !== '' ? $iface : 'unknown', 'virtual' => isVirtual($iface)];
        }
    }

    return $rows;
}

function isVirtual(string $iface): bool
{
    $lower = strtolower($iface);
    foreach (VIRTUAL_HINTS as $hint) {
        if (str_contains($lower, $hint)) {
            return true;
        }
    }

    return false;
}

/**
 * 中文 Windows 的 ipconfig 输出是 GBK，直接正则匹配中文会拿到 ????。
 * 能判成 UTF-8 就原样返回，否则按 CP936 转一下；两条 mbstring 都没装就算了。
 */
function toUtf8(string $raw): string
{
    if ($raw === '' || !function_exists('mb_check_encoding')) {
        return $raw;
    }
    if (mb_check_encoding($raw, 'UTF-8')) {
        return $raw;
    }

    return (string)mb_convert_encoding($raw, 'UTF-8', 'CP936');
}

function isHotspot(string $ip): bool
{
    foreach (HOTSPOT_PREFIXES as $prefix) {
        if (str_starts_with($ip, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * 打分：越大越优先。热点 > 常见家用网段 > 其他 > 虚拟网卡。
 */
function score(string $ip, string $iface, bool $virtual, array $prefer): int
{
    $value = 0;
    if ($virtual) {
        $value -= 100;
    }
    foreach ($prefer as $index => $prefix) {
        if ($prefix !== '' && str_starts_with($ip, $prefix)) {
            $value += 1000 - $index;
        }
    }
    if (isHotspot($ip)) {
        $value += 500;
    } elseif (str_starts_with($ip, '192.168.')) {
        $value += 300;
    } elseif (str_starts_with($ip, '10.')) {
        $value += 200;
    } elseif (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./u', $ip) === 1) {
        $value += 150;
    }

    return $value;
}

/**
 * 探测 URL 是否有响应。
 *
 * 优先 curl 扩展（能区分 4xx/5xx），退到 fsockopen（只证明端口开着）。
 *
 * @return array{ok:bool, code:int, ms:int, method:string}
 */
function probe(string $url, int $timeout): array
{
    $started = microtime(true);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $code > 0,
            'code' => $code,
            'ms' => (int)round((microtime(true) - $started) * 1000),
            'method' => 'curl',
        ];
    }

    $parts = parse_url($url);
    $host = (string)($parts['host'] ?? '');
    $port = (int)($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
    $fp = $host !== '' ? @fsockopen($host, $port, $errno, $errstr, (float)$timeout) : false;
    if ($fp !== false) {
        fclose($fp);
    }

    return [
        'ok' => $fp !== false,
        'code' => $fp === false ? 0 : $port,
        'ms' => (int)round((microtime(true) - $started) * 1000),
        'method' => 'fsockopen',
    ];
}

function buildUrl(string $scheme, string $ip, int $port, string $path): string
{
    $suffix = $port === 80 || $port === 443 ? '' : ':' . $port;
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    return $scheme . '://' . $ip . $suffix . $path;
}

// ---------------------------------------------------------------- main

$options = parseArgs($argv);
$addresses = localAddresses();

if ($addresses === []) {
    if ($options['json']) {
        echo json_encode(['ok' => false, 'error' => 'no-lan-address', 'candidates' => []], JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "没有找到局域网地址。\n";
        echo "  · 确认电脑已经连上 Wi-Fi / 网线 / 手机热点\n";
        echo "  · 只看到 127.0.0.1 说明当前没有网络\n";
    }
    exit(1);
}

// 排序并组装候选
$candidates = [];
foreach ($addresses as $row) {
    $candidates[] = $row + [
        'score' => score($row['ip'], $row['iface'], $row['virtual'], $options['prefer']),
        'hotspot' => isHotspot($row['ip']),
        'url' => buildUrl($options['scheme'], $row['ip'], $options['port'], $options['path']),
    ];
}

usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

$checked = [];
if ($options['check']) {
    foreach ($candidates as $index => $row) {
        $result = probe($row['url'], $options['timeout']);
        $checked[$index] = $result;
        $candidates[$index]['probe'] = $result;
    }
}

$primaryIndex = 0;
if ($options['check']) {
    foreach ($candidates as $index => $row) {
        if (($row['probe']['ok'] ?? false) === true) {
            $primaryIndex = $index;
            break;
        }
    }
}

$primary = $candidates[$primaryIndex];

if ($options['json']) {
    $payload = [
        'ok' => true,
        'url' => $primary['url'],
        'primary' => $primary,
        'candidates' => $candidates,
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$line = static function (array $row, string $mark): void {
    printf(
        "%s %-16s %-26s %-38s%s\n",
        $mark,
        $row['ip'],
        mb_strimwidth($row['iface'], 0, 24, '…'),
        $row['url'],
        isset($row['probe']) ? (($row['probe']['ok'] ? ' ok' : ' 无响应') . ' ' . $row['probe']['ms'] . 'ms') : ''
    );
};

echo "\n";
echo "==============================================\n";
echo " 手机该输入哪个地址\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n\n";

echo " 候选地址（按优先级排序）\n";
foreach ($candidates as $index => $row) {
    $line($row, $index === $primaryIndex ? '✅' : '  ');
}
echo "\n";

if ($options['check']) {
    $ok = ($primary['probe']['ok'] ?? false) === true;
    printf(
        " 探测 %s → %s（%d ms，%s）\n\n",
        $primary['url'],
        $ok ? '有响应' : '没有响应',
        $primary['probe']['ms'] ?? 0,
        $primary['probe']['method'] ?? '-'
    );
    if (!$ok) {
        echo " 本机自己都打不开，先确认服务在跑：\n";
        echo "   · Web 服务：Apache / nginx / php -S 是否已启动\n";
        echo "   · 端口对不对：--port=" . $options['port'] . "\n";
        echo "   · 防火墙：Windows 首次监听会弹窗，允许「专用网络」\n\n";
    }
}

echo "----------------------------------------------\n";
echo " 在手机浏览器里输入：\n\n";
echo "     " . $primary['url'] . "\n\n";
echo "----------------------------------------------\n\n";
echo " 小提示：\n";
echo "   · 手机和电脑要连同一个网络（同一个 Wi-Fi，或电脑连手机热点）\n";
echo "   · 换网络后 IP 会变，重新跑一次即可\n";
echo "   · 电脑睡眠后就连不上了\n";
echo "   · 手机用流量也可以访问，局域网流量不扣费\n\n";

exit(0);
