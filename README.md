# phone-url

用手机打开电脑上的服务，第一步不是打开浏览器，而是**搞清楚该输哪个 IP**。这个脚本把它一句话讲完：列出本机所有局域网地址、按「手机能连上的概率」排序、探测服务是否真的在跑，最后直接把网址打给你。

```
$ php bin/phone-url.php --port=8080

==============================================
 手机该输入哪个地址
 2026-11-10 17:53:43
==============================================

 候选地址（按优先级排序）
✅ 192.168.1.7      Wi-Fi                     http://192.168.1.7:8080/          ok 3ms
   172.20.32.1      vEthernet (Default Swi…   http://172.20.32.1:8080/          无响应 1002ms

 探测 http://192.168.1.7:8080/ → 有响应（3 ms，curl）

----------------------------------------------
 在手机浏览器里输入：

     http://192.168.1.7:8080/

----------------------------------------------
```

单文件、零依赖：只用 PHP 内置函数，`ext-curl` 有就用（能区分 4xx/5xx），没有就退到 `fsockopen`（只证明端口开着）。

---

## 为什么需要它

同一个「局域网访问」问题，实际踩的坑有五个，脚本每个都处理了：

| 坑 | 现象 | 这里的做法 |
|---|---|---|
| 多网卡 | 列出来一堆 IP，不知道哪个是 Wi-Fi | 按热点段 → 192.168 → 10.x → 172.16–31 打分排序，虚拟网卡扣分 |
| 虚拟网卡 | VMware / WSL / Hyper-V 的 IP 手机连不上 | 网卡名命中的（`vEthernet`、`VMware`、`docker`、`wsl`…）直接降权 |
| PPP / 校园网 | `PPP adapter` 看着像局域网，其实手机进不来 | `ppp`、`dial`、`wan miniport` 同样降权，排到真实 Wi-Fi 后面 |
| 服务没起 | 网址输进去转圈，不知道是网络问题还是服务问题 | 本机先自测一次；不通就提示「先确认服务在跑」 |
| 中文乱码 | 控制台代码页是 437 时，`ipconfig` 在**离程序之前**就把「宽带连接」写成 `????`，转码救不回来 | Windows 上先走 `Get-NetAdapter` + `Get-NetIPAddress`（PowerShell 直接输出 UTF-8），`ipconfig` 只用来补 PPP/VPN 这类不在 NetAdapter 里的接口 |

## 快速开始

要求：PHP 8.0+（`str_starts_with` / `str_contains`），Windows / Linux / macOS 都行。

```bash
php bin/phone-url.php                  # 默认 80 端口、根路径
php bin/phone-url.php --port=8080      # 换端口
php bin/phone-url.php --path=/admin    # 带路径
php bin/phone-url.php --json           # 机器可读，方便接进别的脚本
php bin/phone-url.php --no-check       # 不探测，只列地址
php bin/phone-url.php --prefer=192.168.1.   # 指定优先网段（可重复）
```

Windows 上双击 `bin/phone-url.bat` 也一样（它会自动 `chcp 65001` 防乱码）。Linux / macOS 用 `bin/phone-url.sh`。

### 参数

| 参数 | 默认 | 说明 |
|---|---|---|
| `--port=N` | `80` | 端口；80/443 不会写进 URL |
| `--path=/x` | `/` | 路径，自动补前导斜杠 |
| `--scheme=http` | `http` | 可选 `https` |
| `--json` | 关 | 输出 JSON：`{ok,url,primary,candidates[]}` |
| `--no-check` | 关 | 跳过连通性探测 |
| `--timeout=N` | `3` | 探测超时（秒） |
| `--prefer=PREFIX` | 空 | 优先网段，可重复；命中越靠前分越高 |
| `--all` | 关 | 保留字段：把所有候选都当推荐（当前默认就都列出来，仅影响 JSON 语义） |

### 退出码

`0` 找到地址 / `1` 一个局域网地址都没有 / `2` 参数错误。

`--json` 时即使 `ok:false` 也是退出码 1，适合在 CI 或 Agent 里判断。

## 常用场景

**手机开热点、电脑连热点**（最省事，不依赖路由器）：

1. 手机开「个人热点」，电脑连上；
2. 跑 `php bin/phone-url.php --port=8080`；
3. 手机浏览器输入打印出来的那个地址。

热点网段大概率命中 `192.168.43.` / `172.20.10.` / `192.168.137.`，脚本会把它排在第一位。

**电脑连 Wi-Fi、手机也连同一个 Wi-Fi**：同上，注意路由器可能开了「AP 隔离」，开了就互相 ping 不通，只能换热点。

**防火墙**：Windows 首次监听端口会弹「是否允许访问」，选**专用网络**；错过弹窗就到「Windows 安全中心 → 防火墙 → 允许应用通过」里补。

## 已知限制

- 只处理 IPv4：IPv6 的地址形态和「手机能不能输入」完全是另一个问题，这里不做。
- 中文 Windows 下若 PowerShell 不可用，会退回 `ipconfig`，此时接口名可能显示成 `PPP adapter [name unreadable in this code page]`：字符在进 PHP 之前就被控制台代码页替换了，IP 与 URL 仍然是对的。
- Windows 分支需要 PowerShell（`Get-NetAdapter`）；极简 PE 或禁用 PowerShell 的环境会自动降级到 `ipconfig`。
- 探测只到「有响应」：返回 302 跳登录页也算通，它不判断内容。
- `PPP adapter`（校园网客户端 / VPN）虽然降权了，但如果你确实是通过 VPN 组网访问，得手动 `--prefer=` 指定那个网段。

## 许可证

MIT © 2026 Reid — 见 [LICENSE](LICENSE)。
