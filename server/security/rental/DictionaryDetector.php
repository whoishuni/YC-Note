<?php
/**
 * DictionaryDetector.php — 字典偵測引擎
 * 
 * 從 SmartSecurityGuard 提取的純偵測邏輯
 * 使用 regex 模式比對和風險評分機制偵測惡意輸入
 * 
 * 此模組為純函式，不做任何阻擋或記錄操作
 * 所有處理邏輯由 SecurityPipeline 統一管理
 * 
 * @version 2.0
 */

class DictionaryDetector {
    
    // ========== 風險模式定義 ==========
    
    // 高危險 SQL 注入模式（每個匹配 +30-50 分）
    private static $highRiskSqlPatterns = [
        ['pattern' => '/\bunion\s+(all\s+)?select\b/i', 'score' => 50, 'name' => 'UNION SELECT'],
        ['pattern' => '/\binsert\s+into\s+[\w`]+/i', 'score' => 45, 'name' => 'INSERT INTO'],
        ['pattern' => '/\bdelete\s+from\s+[\w`]+/i', 'score' => 45, 'name' => 'DELETE FROM'],
        ['pattern' => '/\bdrop\s+(table|database|column|view|index)\b/i', 'score' => 50, 'name' => 'DROP'],
        ['pattern' => '/\bupdate\s+[\w`]+\s+set\b/i', 'score' => 40, 'name' => 'UPDATE SET'],
        ['pattern' => '/\btruncate\s+table\b/i', 'score' => 50, 'name' => 'TRUNCATE'],
        ['pattern' => '/\bexec(ute)?\s*\(/i', 'score' => 45, 'name' => 'EXEC()'],
        ['pattern' => '/\bxp_cmdshell\b/i', 'score' => 50, 'name' => 'xp_cmdshell'],
        ['pattern' => '/\balter\s+table\b/i', 'score' => 45, 'name' => 'ALTER TABLE'],
        ['pattern' => '/\bcreate\s+(table|database|user)\b/i', 'score' => 45, 'name' => 'CREATE'],
    ];
    
    // 中等風險 SQL 模式（每個匹配 +15-25 分）
    private static $mediumRiskSqlPatterns = [
        ['pattern' => '/\'\s*(or|and)\s+[\'"]\d+[\'"]?\s*[=><]/i', 'score' => 25, 'name' => "' OR/AND 數字比較"],
        ['pattern' => '/\bor\b\s+1\s*=\s*1/i', 'score' => 30, 'name' => 'OR 1=1'],
        ['pattern' => '/\bor\b\s+\'.*\'\s*=\s*\'/i', 'score' => 25, 'name' => "OR ''=''"],
        ['pattern' => '/[\'"]\s*;\s*--\s*/i', 'score' => 20, 'name' => 'SQL 註釋 --'],
        ['pattern' => '/\bsleep\s*\(\s*\d+\s*\)/i', 'score' => 30, 'name' => 'SLEEP()'],
        ['pattern' => '/\bbenchmark\s*\(/i', 'score' => 30, 'name' => 'BENCHMARK()'],
        ['pattern' => '/\binto\s+(out|dump)file\b/i', 'score' => 35, 'name' => 'INTO OUTFILE'],
        ['pattern' => '/\bload_file\s*\(/i', 'score' => 35, 'name' => 'LOAD_FILE()'],
        ['pattern' => '/\bwaitfor\s+delay\b/i', 'score' => 30, 'name' => 'WAITFOR DELAY'],
        ['pattern' => '/\bIF\s*\(\s*\d+\s*=\s*\d+/i', 'score' => 25, 'name' => 'IF() 盲注'],
        ['pattern' => '/\/\*!.*\*\//i', 'score' => 25, 'name' => 'MySQL 版本註釋繞過'],
        ['pattern' => '/\bchar\s*\(\s*\d+\s*(,\s*\d+\s*){2,}/i', 'score' => 35, 'name' => 'CHAR() 多參數編碼繞過'],
        ['pattern' => '/0x[0-9a-f]{8,}/i', 'score' => 30, 'name' => 'HEX 編碼繞過'],
        ['pattern' => '/\bconcat\s*\(\s*(0x|char|\d)/i', 'score' => 30, 'name' => 'CONCAT 編碼繞過'],
        ['pattern' => '/\bunhex\s*\(/i', 'score' => 30, 'name' => 'UNHEX() 編碼繞過'],
    ];
    
    // 低風險 SQL 模式
    private static $lowRiskSqlPatterns = [
        ['pattern' => '/\bselect\b.*\bfrom\b/i', 'score' => 10, 'name' => 'SELECT FROM', 'requireContext' => true],
        ['pattern' => '/\(\s*select\b/i', 'score' => 15, 'name' => '子查詢'],
        ['pattern' => '/\bconcat\s*\(/i', 'score' => 10, 'name' => 'CONCAT()', 'requireContext' => true],
        ['pattern' => '/\bchar\s*\(\s*\d+/i', 'score' => 15, 'name' => 'CHAR() 編碼'],
        ['pattern' => '/\bgroup_concat\s*\(/i', 'score' => 15, 'name' => 'GROUP_CONCAT()'],
        ['pattern' => '/\binformation_schema\b/i', 'score' => 20, 'name' => 'INFORMATION_SCHEMA'],
    ];
    
    // XSS 攻擊模式
    private static $xssPatterns = [
        ['pattern' => '/<script[^>]*>/i', 'score' => 50, 'name' => '<script>'],
        ['pattern' => '/javascript\s*:/i', 'score' => 45, 'name' => 'javascript:'],
        ['pattern' => '/java\s*script\s*:/i', 'score' => 45, 'name' => 'javascript: 空白繞過'],
        ['pattern' => '/javascript\s*&\s*#\s*58\s*;/i', 'score' => 45, 'name' => 'javascript: HTML實體繞過'],
        ['pattern' => '/on(load|error|click|mouseover|focus|submit|input|change|keyup|keydown|mouseenter|blur|dblclick|contextmenu|drag|drop|scroll|wheel|touchstart|touchend|touchmove|pointerdown|pointerup|pointermove|animationstart|animationend|transitionend|resize|hashchange|popstate|pageshow|beforeunload|message|begin|readystatechange|search|toggle|invalid|reset|select|copy|cut|paste|abort|canplay|ended|playing|seeked|volumechange|waiting)\s*=/i', 'score' => 50, 'name' => '事件處理器'],
        ['pattern' => '/<iframe/i', 'score' => 50, 'name' => '<iframe>'],
        ['pattern' => '/<img[^>]*on\w+\s*=/i', 'score' => 45, 'name' => '<img 事件>'],
        ['pattern' => '/\bdocument\.(cookie|location|write|domain|referrer)/i', 'score' => 45, 'name' => 'document 操作'],
        ['pattern' => '/data:text\/html/i', 'score' => 45, 'name' => 'data:text/html'],
        ['pattern' => '/<svg[^>]*on\w+\s*=/i', 'score' => 45, 'name' => '<svg 事件>'],
        ['pattern' => '/<svg>\s*<(animate|set|use)[^>]*on\w+\s*=/i', 'score' => 45, 'name' => '<svg 子元素事件>'],
        ['pattern' => '/<(embed|object|applet|form|base|link|meta)\b/i', 'score' => 50, 'name' => '危險 HTML 標籤'],
        ['pattern' => '/\bwindow\.(location|open|eval|execScript)/i', 'score' => 45, 'name' => 'window 操作'],
        ['pattern' => '/&#x?[0-9a-f]+;.*&#x?[0-9a-f]+;/i', 'score' => 30, 'name' => 'HTML 編碼繞過'],
        ['pattern' => '/\\\\u003[cC]/i', 'score' => 30, 'name' => 'Unicode 編碼繞過'],
        ['pattern' => '/expression\s*\(/i', 'score' => 45, 'name' => 'CSS expression()'],
        ['pattern' => '/vbscript\s*:/i', 'score' => 45, 'name' => 'vbscript:'],
    ];
    
    // 代碼注入模式
    private static $codeInjectionPatterns = [
        ['pattern' => '/\beval\s*\(/i', 'score' => 50, 'name' => 'eval()'],
        ['pattern' => '/\bshell_exec\s*\(/i', 'score' => 50, 'name' => 'shell_exec()'],
        ['pattern' => '/\bsystem\s*\(/i', 'score' => 50, 'name' => 'system()'],
        ['pattern' => '/<\?php\b/i', 'score' => 45, 'name' => 'PHP 標籤'],
        ['pattern' => '/\bpassthru\s*\(/i', 'score' => 50, 'name' => 'passthru()'],
        ['pattern' => '/php:\/\/(input|filter|data)/i', 'score' => 45, 'name' => 'PHP wrapper'],
        ['pattern' => '/\bpreg_replace\s*\(.*\/e/i', 'score' => 45, 'name' => 'preg_replace /e'],
        ['pattern' => '/\bassert\s*\(/i', 'score' => 40, 'name' => 'assert()'],
        ['pattern' => '/\bproc_open\s*\(/i', 'score' => 50, 'name' => 'proc_open()'],
        ['pattern' => '/\bpopen\s*\(/i', 'score' => 50, 'name' => 'popen()'],
        ['pattern' => '/\bexec\s*\(/i', 'score' => 45, 'name' => 'exec()'],
        ['pattern' => '/\bcurl_exec\s*\(/i', 'score' => 40, 'name' => 'curl_exec()'],
        ['pattern' => '/\bfile_get_contents\s*\(\s*[\'"]?(https?:|ftp:|php:|data:)/i', 'score' => 35, 'name' => 'file_get_contents() 遠端'],
        ['pattern' => '/\binclude(_once)?\s*\(\s*[\$\'"].*\.(php|inc|txt)/i', 'score' => 40, 'name' => 'include() 動態'],
        ['pattern' => '/\brequire(_once)?\s*\(\s*[\$\'"].*\.(php|inc|txt)/i', 'score' => 40, 'name' => 'require() 動態'],
        ['pattern' => '/\bbase64_decode\s*\(/i', 'score' => 25, 'name' => 'base64_decode()', 'requireContext' => true],
        ['pattern' => '/\bcreate_function\s*\(/i', 'score' => 50, 'name' => 'create_function()'],
        ['pattern' => '/\bcall_user_func(_array)?\s*\(/i', 'score' => 30, 'name' => 'call_user_func()', 'requireContext' => true],
        ['pattern' => '/\barray_map\s*\(\s*[\'"](system|exec|passthru|shell_exec)/i', 'score' => 50, 'name' => 'array_map() 代碼執行'],
    ];
    
    // 路徑遍歷模式
    private static $pathTraversalPatterns = [
        ['pattern' => '/\.\.\/.*\.\./i', 'score' => 40, 'name' => '多層 ../'],
        ['pattern' => '/\.\.\/\.\.\//i', 'score' => 45, 'name' => '連續 ../../'],
        ['pattern' => '/%2e%2e[%\/\\\\]/i', 'score' => 40, 'name' => 'URL 編碼 ../'],
        ['pattern' => '/\.\.%2[fF]/i', 'score' => 40, 'name' => '..%2f 部分編碼'],
        ['pattern' => '/\.\.%5[cC]/i', 'score' => 40, 'name' => '..%5c 部分編碼'],
        ['pattern' => '/%25?2[eE]%25?2[eE]/i', 'score' => 40, 'name' => '雙重編碼 ../'],
        ['pattern' => '/%c0%ae|%ef%bc%8f|%c0%af/i', 'score' => 40, 'name' => 'UTF-8 過長編碼繞過'],
        ['pattern' => '/\/etc\/(passwd|shadow|hosts|my\.cnf|apache2|nginx|php|crontab|resolv|sysctl|ssh|fstab|group|profile|hostname|issue)/i', 'score' => 50, 'name' => 'Linux 敏感檔案'],
        ['pattern' => '/c:[\\\\\\/](windows|boot\.ini|inetpub|users|programdata|program\s*files)/i', 'score' => 45, 'name' => 'Windows 系統路徑'],
        ['pattern' => '/%00/', 'score' => 40, 'name' => 'Null byte'],
        ['pattern' => '/\/proc\/self\/(environ|fd|cmdline|status|maps|cwd|exe|mounts|net)/i', 'score' => 50, 'name' => '/proc/self'],
        ['pattern' => '/\/proc\/version/i', 'score' => 45, 'name' => '/proc/version'],
        ['pattern' => '/\.\.\\\\/i', 'score' => 40, 'name' => '..\\\ 反斜線遍歷'],
        ['pattern' => '/\.\.\.\.\/\//i', 'score' => 40, 'name' => '....// 繞過'],
        ['pattern' => '/\.(bash_history|ssh\/id_rsa|ssh\/authorized_keys|gitconfig|env|htpasswd)/i', 'score' => 45, 'name' => '敏感隱藏檔案'],
        ['pattern' => '/\/var\/log\/(auth|syslog|apache2|nginx|mysql|secure|messages)/i', 'score' => 40, 'name' => '系統日誌檔案'],
        ['pattern' => '/web\.config|wp-config\.php|\.htaccess|\.htpasswd/i', 'score' => 45, 'name' => 'Web 設定檔'],
    ];
    
    // SSRF / 檔案包含模式
    private static $ssrfPatterns = [
        ['pattern' => '/file:\/\/\//i', 'score' => 45, 'name' => 'file:// SSRF'],
        ['pattern' => '/gopher:\/\//i', 'score' => 45, 'name' => 'gopher:// SSRF'],
        ['pattern' => '/dict:\/\//i', 'score' => 40, 'name' => 'dict:// SSRF'],
        ['pattern' => '/expect:\/\//i', 'score' => 50, 'name' => 'expect:// wrapper'],
        ['pattern' => '/php:\/\/filter/i', 'score' => 45, 'name' => 'php://filter LFI'],
    ];
    
    // LDAP / XML / SSTI 注入模式
    private static $otherInjectionPatterns = [
        ['pattern' => '/\*\)\s*[\(|]/i', 'score' => 40, 'name' => 'LDAP 注入'],
        ['pattern' => '/<!ENTITY\s+/i', 'score' => 45, 'name' => 'XML Entity (XXE)'],
        ['pattern' => '/<!DOCTYPE[^>]*SYSTEM/i', 'score' => 45, 'name' => 'XXE DOCTYPE'],
        ['pattern' => '/<!DOCTYPE[^>]*\[\s*<!ENTITY/i', 'score' => 50, 'name' => 'XXE 嵌入 Entity'],
        ['pattern' => '/\{\{.*\}\}/i', 'score' => 30, 'name' => 'SSTI {{}}'],
        ['pattern' => '/\$\{.*\}/i', 'score' => 30, 'name' => 'SSTI ${}'],
        ['pattern' => '/<%= ?.*%>/i', 'score' => 45, 'name' => 'ERB/ASP 模板注入'],
        ['pattern' => '/<% .*%>/i', 'score' => 45, 'name' => 'ASP 代碼塊'],
    ];
    
    // OS 命令注入模式（非 PHP 函式的命令注入）
    private static $osCommandPatterns = [
        ['pattern' => '/;\s*(cat|ls|pwd|whoami|id|uname|wget|curl|nc|ncat|ifconfig|netstat|ps|env|hostname|uptime|ping|nslookup|dig|chmod|useradd|echo|tar|scp|rsync|rm|find|grep|awk|sed|dd|mount|kill|crontab|chown|mkfs|fdisk|ip|ss|traceroute|tcpdump|nmap|python|perl|ruby|php|gcc|make)\b/i', 'score' => 45, 'name' => '; 命令串接'],
        ['pattern' => '/\|\s*(cat|ls|pwd|whoami|id|uname|wget|curl|nc|ncat|ifconfig|netstat|ps|env|hostname|uptime|ping|nslookup|dig|chmod|useradd|echo|tar|scp|rsync|rm|find|grep|awk|sed|dd|mount|kill|crontab|chown|mkfs|fdisk|ip|ss|traceroute|tcpdump|nmap|python|perl|ruby|php|gcc|make)\b/i', 'score' => 45, 'name' => '| 管道命令'],
        ['pattern' => '/`[^`]*`/', 'score' => 40, 'name' => '反引號命令'],
        ['pattern' => '/\$\([^)]+\)/', 'score' => 35, 'name' => '$() 子命令'],
        ['pattern' => '/&&\s*(cat|ls|pwd|whoami|id|uname|wget|curl|nc|ncat|ifconfig|netstat|ps|env|hostname|uptime|ping|nslookup|dig|chmod|useradd|echo|tar|scp|rsync|rm|find|grep|awk|sed|dd|mount|kill|crontab|chown|mkfs|fdisk|ip|ss|traceroute|tcpdump|nmap|python|perl|ruby|php|gcc|make)\b/i', 'score' => 45, 'name' => '&& 命令串接'],
        ['pattern' => '/\|\|\s*(cat|ls|pwd|whoami|id|uname|wget|curl|nc|ncat|ifconfig|netstat|ps|env|hostname|uptime|ping|nslookup|dig|rm|find|grep|chmod|useradd|echo|tar|scp|rsync|awk|sed|dd|mount|kill|crontab|chown|mkfs|fdisk|ip|ss|traceroute|tcpdump|nmap|python|perl|ruby|php|gcc|make)\b/i', 'score' => 45, 'name' => '|| 命令串接'],
        ['pattern' => '/;\s*\/bin\/(sh|bash|dash|csh|ksh|zsh)/i', 'score' => 50, 'name' => '; shell 執行'],
        ['pattern' => '/\|\s*\/bin\/(sh|bash|dash)/i', 'score' => 50, 'name' => '| shell 執行'],
        ['pattern' => '/;\s*(apt-get|yum|dnf|pacman|brew)\s+(install|remove)/i', 'score' => 45, 'name' => '; 套件管理器'],
    ];
    
    // Header 注入模式
    private static $headerInjectionPatterns = [
        ['pattern' => '/%0[dD]%0[aA]/i', 'score' => 40, 'name' => 'CRLF URL 編碼'],
        ['pattern' => '/%0[dD]|%0[aA]/i', 'score' => 30, 'name' => 'CR 或 LF 單獨編碼'],
        ['pattern' => '/\\\\n\s*(Host|Set-Cookie|Location|Content-Type|Content-Length|Transfer-Encoding|Refresh|Proxy|Content-Disposition|Accept|Authorization|Cookie|Access-Control|X-)[\w-]*\s*:/i', 'score' => 45, 'name' => 'Header 注入 (文字換行)'],
        ['pattern' => '/\nHost:\s*/i', 'score' => 45, 'name' => 'Host Header 注入'],
        ['pattern' => '/[\r\n].*(X-Injected|X-Forwarded|Set-Cookie|Location|Content-Type|Content-Length|Transfer-Encoding|Refresh|Proxy|Authorization|Access-Control)[\w-]*\s*:/i', 'score' => 45, 'name' => 'HTTP Header 注入'],
        ['pattern' => '/\\\\n\s*(Content-Type|Content-Length|Transfer-Encoding|Refresh|Proxy|Access-Control|X-Frame|X-XSS)[\w-]*\s*:/i', 'score' => 45, 'name' => '文字 backslash-n Header 注入'],
        ['pattern' => '/\n(Content-Type|Content-Length|Transfer-Encoding|Refresh|Proxy|Access-Control)[\w-]*\s*:/i', 'score' => 45, 'name' => '敏感 Header 換行注入'],
    ];
    
    // NoSQL 注入模式
    private static $nosqlPatterns = [
        ['pattern' => '/\$\s*(gt|gte|lt|lte|ne|eq|in|nin|exists|regex|where|or|and|not|nor)\b/i', 'score' => 40, 'name' => 'MongoDB 操作符'],
        ['pattern' => '/\{\s*[\'"]?\$(gt|gte|lt|lte|ne|eq|in|nin|exists|regex|where|or|and|not|nor)[\'"]?\s*:/i', 'score' => 50, 'name' => 'MongoDB JSON 注入'],
        ['pattern' => '/db\s*\.\s*\w+\s*\.\s*(find|findOne|insert|update|delete|drop|remove|aggregate|count|distinct)\s*\(/i', 'score' => 50, 'name' => 'MongoDB 集合操作'],
        ['pattern' => '/\$\s*(lookup|unwind|group|match|project|sort|limit|skip|out|merge)\b/i', 'score' => 40, 'name' => 'MongoDB 聚合管道'],
        ['pattern' => '/\{\s*[\'"]?\$where[\'"]?\s*:\s*[\'"].*function/i', 'score' => 50, 'name' => 'MongoDB $where 函式注入'],
        ['pattern' => '/this\.\w+\s*(==|!=|>|<|>=|<=)/i', 'score' => 30, 'name' => 'MongoDB this 引用', 'requireContext' => true],
        ['pattern' => '/\[\s*[\'"]\$\w+[\'"]\s*\]/i', 'score' => 35, 'name' => 'NoSQL 操作符陣列注入'],
        ['pattern' => '/sleep\s*\(\s*\d+\s*\).*\$where/i', 'score' => 50, 'name' => 'MongoDB 時間盲注'],
    ];
    
    // 反序列化攻擊模式
    private static $deserializationPatterns = [
        ['pattern' => '/\bunserialize\s*\(/i', 'score' => 50, 'name' => 'PHP unserialize()'],
        ['pattern' => '/O:\d+:"[^"]+":\d+:\{/i', 'score' => 50, 'name' => 'PHP 序列化物件'],
        ['pattern' => '/a:\d+:\{(s:\d+:"[^"]*";)+/i', 'score' => 35, 'name' => 'PHP 序列化陣列'],
        ['pattern' => '/rO0AB[A-Za-z0-9+\/=]/i', 'score' => 50, 'name' => 'Java 序列化 (Base64)'],
        ['pattern' => '/\bObjectInputStream/i', 'score' => 45, 'name' => 'Java ObjectInputStream'],
        ['pattern' => '/\bpickle\.(loads?|dump)/i', 'score' => 45, 'name' => 'Python pickle'],
        ['pattern' => '/\byaml\.(load|unsafe_load|full_load)/i', 'score' => 45, 'name' => 'YAML 反序列化'],
        ['pattern' => '/\bjson_decode\s*\(.*\beval\b/i', 'score' => 45, 'name' => 'JSON decode + eval'],
        ['pattern' => '/\bmarshall?\.(loads?|dumps?)/i', 'score' => 40, 'name' => 'Python marshal'],
    ];
    
    // Log4j / Log 注入模式
    private static $logInjectionPatterns = [
        ['pattern' => '/\$\{jndi:(ldap|rmi|dns|iiop|corba|nds|http|nis):\/\//i', 'score' => 50, 'name' => 'Log4j JNDI 注入'],
        ['pattern' => '/\$\{(upper|lower|env|sys|java|ctx):/i', 'score' => 45, 'name' => 'Log4j 變數查找'],
        ['pattern' => '/\$\{[\w:]*\$\{[\w:]*\}\}/i', 'score' => 45, 'name' => 'Log4j 嵌套查找'],
        ['pattern' => '/\$\{(j|J)(n|N)(d|D)(i|I)/i', 'score' => 50, 'name' => 'Log4j JNDI 大小寫混合'],
        ['pattern' => '/\$\{\$\{(lower|upper):[jJ]\}/i', 'score' => 50, 'name' => 'Log4j 編碼繞過'],
    ];
    
    // Open Redirect 模式
    private static $openRedirectPatterns = [
        ['pattern' => '/\/\/[\w.-]+\.(com|net|org|io|xyz|tk|ml|ga|cf|ru|cn)\b/i', 'score' => 35, 'name' => '// 開放重定向'],
        ['pattern' => '/\\\\\\/\\\\\\//i', 'score' => 35, 'name' => '\\/ 重定向繞過'],
        ['pattern' => '/%2[fF]%2[fF][\w.-]+\./i', 'score' => 35, 'name' => 'URL 編碼重定向'],
        ['pattern' => '/\/\/0x[0-9a-f]+/i', 'score' => 40, 'name' => 'HEX IP 重定向'],
        ['pattern' => '/\/\/\d{8,10}\b/', 'score' => 40, 'name' => '十進制 IP 重定向'],
        ['pattern' => '/@[\w.-]+\.(com|net|org|io)/i', 'score' => 30, 'name' => '@ 認證重定向', 'requireContext' => true],
    ];
    
    // CSV 注入模式
    private static $csvInjectionPatterns = [
        ['pattern' => '/^\s*[=+\-@]\s*(cmd|CMD)\s*\(/i', 'score' => 50, 'name' => 'CSV 命令注入'],
        ['pattern' => '/^\s*=\s*(HYPERLINK|IMPORTDATA|IMPORTXML|IMPORTRANGE|IMAGE)\s*\(/i', 'score' => 50, 'name' => 'CSV 公式注入'],
        ['pattern' => '/^\s*[=+\-@]\s*(SUM|IF|CONCAT|INDIRECT|EVALUATE|CALL|DDE)\s*\(/i', 'score' => 45, 'name' => 'CSV 危險函式'],
        ['pattern' => '/^\s*=\s*\'.*\'\s*!/i', 'score' => 40, 'name' => 'CSV DDE 注入'],
        ['pattern' => '/\|\s*\bDDE\b/i', 'score' => 50, 'name' => 'DDE 協定注入'],
    ];
    
    // Prototype Pollution 模式
    private static $prototypePollutionPatterns = [
        ['pattern' => '/__proto__/i', 'score' => 50, 'name' => '__proto__ 污染'],
        ['pattern' => '/constructor\s*\.\s*prototype/i', 'score' => 50, 'name' => 'constructor.prototype 污染'],
        ['pattern' => '/constructor\s*\[\s*[\'"]prototype/i', 'score' => 50, 'name' => 'constructor["prototype"] 污染'],
        ['pattern' => '/Object\s*\.\s*(assign|defineProperty|setPrototypeOf)\s*\(/i', 'score' => 45, 'name' => 'Object 原型操作'],
        ['pattern' => '/\[\s*[\'"]__proto__[\'"]\s*\]/i', 'score' => 50, 'name' => '["__proto__"] 污染'],
    ];
    
    // 白名單：文本欄位使用較寬鬆的檢測
    private static $relaxedFields = [
        'note', 'desc', 'description', 'comment', 'content', 'message', 
        'addr', 'address', 'memo', 'remark', 'instruction', 'info',
        'title', 'announcement', 'body', 'text', 'detail', 'summary',
        'reason', 'feedback', 'review'
    ];
    
    // 不需要檢查的欄位
    private static $skipFields = [
        'csrf_token', '_token', 'token', 'id_token', 'access_token', 'sign_token', 'reset_token', 'renewal_token', 'refund_token',
        'signature',
        'password', 'password_confirm', 'current_password',
        'old_password', 'new_password', 'confirm_password',
    ];
    
    /**
     * 偵測所有輸入的風險
     * 
     * @param array $inputs ['key' => 'value'] 的輸入陣列
     * @param string $source 輸入來源 ('GET' 或 'POST')
     * @return array ['score' => int, 'threats' => [], 'attack_type' => string, 'field_scores' => []]
     */
    public static function detect($inputs, $source = 'POST') {
        $totalScore = 0;
        $allThreats = [];
        $fieldScores = [];
        
        foreach ($inputs as $key => $value) {
            // 跳過不需要檢查的欄位
            $normalizedKey = strtolower((string) $key);
            $normalizedKey = preg_replace('/^(get|post)_/', '', $normalizedKey);
            if (in_array($normalizedKey, self::$skipFields, true)) {
                continue;
            }
            
            // 遞迴處理陣列
            if (is_array($value)) {
                $subResult = self::detect($value, $source);
                $totalScore = max($totalScore, $subResult['score']);
                $allThreats = array_merge($allThreats, $subResult['threats']);
                $fieldScores = array_merge($fieldScores, $subResult['field_scores']);
                continue;
            }
            
            if (!is_string($value) || strlen($value) < 3) {
                $fieldScores[$key] = 0;
                continue;
            }
            
            // 分析單一欄位
            $result = self::analyzeField($value, $key);
            $fieldScores[$key] = $result['score'];
            
            if ($result['score'] > $totalScore) {
                $totalScore = $result['score'];
            }
            
            $allThreats = array_merge($allThreats, $result['threats']);
        }
        
        // 去除重複威脅
        $allThreats = array_unique($allThreats);
        
        return [
            'score' => min(100, $totalScore),
            'threats' => array_values($allThreats),
            'attack_type' => self::determineAttackType($allThreats),
            'field_scores' => $fieldScores,
        ];
    }
    
    /**
     * 分析單一欄位的風險
     */
    public static function analyzeField($value, $key = '') {
        $isTextField = self::isTextField($key);
        $score = 0;
        $threats = [];
        
        // SQL 注入風險
        $sqlResult = self::assessSqlRisk($value, $isTextField);
        $score += $sqlResult['score'];
        $threats = array_merge($threats, $sqlResult['threats']);
        
        // XSS 風險
        $xssResult = self::assessPatternRisk($value, self::$xssPatterns);
        $score += $xssResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "XSS: $t", $xssResult['threats']));
        
        // 代碼注入風險
        $codeResult = self::assessPatternRisk($value, self::$codeInjectionPatterns);
        $score += $codeResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "Code: $t", $codeResult['threats']));
        
        // 路徑遍歷風險
        $pathResult = self::assessPatternRisk($value, self::$pathTraversalPatterns);
        $score += $pathResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "Path: $t", $pathResult['threats']));
        
        // SSRF / 檔案包含
        $ssrfResult = self::assessPatternRisk($value, self::$ssrfPatterns);
        $score += $ssrfResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "SSRF: $t", $ssrfResult['threats']));
        
        // LDAP / XML / SSTI
        $otherResult = self::assessPatternRisk($value, self::$otherInjectionPatterns);
        $score += $otherResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "Inject: $t", $otherResult['threats']));
        
        // OS 命令注入
        $osResult = self::assessPatternRisk($value, self::$osCommandPatterns);
        $score += $osResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "OS: $t", $osResult['threats']));
        
        // Header 注入
        $headerResult = self::assessPatternRisk($value, self::$headerInjectionPatterns);
        $score += $headerResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "Header: $t", $headerResult['threats']));
        
        // NoSQL 注入
        $nosqlResult = self::assessPatternRisk($value, self::$nosqlPatterns);
        $score += $nosqlResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "NoSQL: $t", $nosqlResult['threats']));
        
        // 反序列化攻擊
        $deserResult = self::assessPatternRisk($value, self::$deserializationPatterns);
        $score += $deserResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "Deser: $t", $deserResult['threats']));
        
        // Log4j / Log 注入
        $logResult = self::assessPatternRisk($value, self::$logInjectionPatterns);
        $score += $logResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "Log: $t", $logResult['threats']));
        
        // Open Redirect
        $redirectResult = self::assessPatternRisk($value, self::$openRedirectPatterns);
        $score += $redirectResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "Redirect: $t", $redirectResult['threats']));
        
        // CSV 注入
        $csvResult = self::assessPatternRisk($value, self::$csvInjectionPatterns);
        $score += $csvResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "CSV: $t", $csvResult['threats']));
        
        // Prototype Pollution
        $protoResult = self::assessPatternRisk($value, self::$prototypePollutionPatterns);
        $score += $protoResult['score'];
        $threats = array_merge($threats, array_map(fn($t) => "Proto: $t", $protoResult['threats']));
        
        // 文本欄位降低分數（正常內容可能含有關鍵字）
        // 高危模式（≥ 45 分）不降分，只對中低風險降分
        if ($isTextField && $score > 0 && $score < 45) {
            $score = (int)($score * 0.7);
        }
        
        return [
            'score' => min(100, $score),
            'threats' => $threats,
        ];
    }
    
    /**
     * 判斷是否為文本欄位
     */
    private static function isTextField($key) {
        $lowerKey = strtolower($key);
        foreach (self::$relaxedFields as $field) {
            if (strpos($lowerKey, $field) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 評估 SQL 注入風險
     */
    private static function assessSqlRisk($value, $isTextField) {
        $score = 0;
        $threats = [];
        
        // 高風險
        foreach (self::$highRiskSqlPatterns as $item) {
            if (preg_match($item['pattern'], $value)) {
                $score += $item['score'];
                $threats[] = "SQL-High: " . $item['name'];
            }
        }
        
        // 中等風險
        foreach (self::$mediumRiskSqlPatterns as $item) {
            if (preg_match($item['pattern'], $value)) {
                $score += $item['score'];
                $threats[] = "SQL-Med: " . $item['name'];
            }
        }
        
        // 低風險（需要搭配其他特徵）
        if ($score > 0 || !$isTextField) {
            foreach (self::$lowRiskSqlPatterns as $item) {
                if (preg_match($item['pattern'], $value)) {
                    if (!($item['requireContext'] ?? false) || $score > 0) {
                        $score += $item['score'];
                        $threats[] = "SQL-Low: " . $item['name'];
                    }
                }
            }
        }
        
        return ['score' => $score, 'threats' => $threats];
    }
    
    /**
     * 通用模式風險評估
     */
    private static function assessPatternRisk($value, $patterns) {
        $score = 0;
        $threats = [];
        
        foreach ($patterns as $item) {
            if (preg_match($item['pattern'], $value)) {
                // 有上下文需求的模式需要已有其他分數
                if (($item['requireContext'] ?? false) && $score === 0) {
                    continue;
                }
                $score += $item['score'];
                $threats[] = $item['name'];
            }
        }
        
        return ['score' => $score, 'threats' => $threats];
    }
    
    /**
     * 判斷主要攻擊類型
     */
    private static function determineAttackType($threats) {
        if (empty($threats)) return 'none';
        
        $counts = ['sql' => 0, 'xss' => 0, 'code' => 0, 'path' => 0, 'ssrf' => 0, 'inject' => 0, 'os' => 0, 'header' => 0, 'nosql' => 0, 'deser' => 0, 'log' => 0, 'redirect' => 0, 'csv' => 0, 'proto' => 0];
        
        foreach ($threats as $threat) {
            if (stripos($threat, 'SQL') !== false) $counts['sql']++;
            if (stripos($threat, 'XSS') !== false) $counts['xss']++;
            if (stripos($threat, 'Code') !== false) $counts['code']++;
            if (stripos($threat, 'Path') !== false) $counts['path']++;
            if (stripos($threat, 'SSRF') !== false) $counts['ssrf']++;
            if (stripos($threat, 'Inject') !== false) $counts['inject']++;
            if (stripos($threat, 'OS') !== false) $counts['os']++;
            if (stripos($threat, 'Header') !== false) $counts['header']++;
            if (stripos($threat, 'NoSQL') !== false) $counts['nosql']++;
            if (stripos($threat, 'Deser') !== false) $counts['deser']++;
            if (stripos($threat, 'Log') !== false) $counts['log']++;
            if (stripos($threat, 'Redirect') !== false) $counts['redirect']++;
            if (stripos($threat, 'CSV') !== false) $counts['csv']++;
            if (stripos($threat, 'Proto') !== false) $counts['proto']++;
        }
        
        arsort($counts);
        $type = key($counts);
        
        $typeMap = [
            'sql' => 'sql_injection',
            'xss' => 'xss',
            'code' => 'code_injection',
            'path' => 'path_traversal',
            'ssrf' => 'ssrf',
            'inject' => 'injection',
            'os' => 'os_command_injection',
            'header' => 'header_injection',
            'nosql' => 'nosql_injection',
            'deser' => 'deserialization',
            'log' => 'log_injection',
            'redirect' => 'open_redirect',
            'csv' => 'csv_injection',
            'proto' => 'prototype_pollution',
        ];
        
        return $typeMap[$type] ?? 'unknown';
    }
}
