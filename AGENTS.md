# YC Note 工作規則

- 這是獨立的 Saber fork。租屋系統位於相鄰的 Rental-management-system；不得混入租屋業務頁面或改動既有租屋使用者資料。
- 正式網址：https://yckuo.com/yc_note/。筆記資料庫為 u805049489_ycnote；筆記與憑證必須位於 public_html 外。
- UI 比照租屋系統：簡潔、乾淨、有質感。網站與 App 都只保留名稱、必要狀態、操作與錯誤訊息。禁止標語、歡迎文案、宣傳段落、技術堆砌、裝飾性說明、Emoji。
- 名稱 YC Note；紫色漸層 YC 圖示，不含房屋。圖示以 repo 原生 SVG 維護。
- 租屋 super_admin → YC Note super_admin；admin 與舊 manager → YC Note user；租客不開放。YC Note 可新增獨立筆記帳號；新增、移除、權限調整不能修改租屋 users。
- 移除編輯權限必須變成唯讀：仍可閱讀和下載，禁止新增、修改、刪除、還原。App 編輯器、本機檔案操作與 API 寫入端都要落實，不能只隱藏按鈕。
- 每台 App 裝置須管理者核准。裝置撤銷與帳號移除會撤銷伺服器存取。離線驗證有期限，不能宣稱離線裝置可瞬間撤銷。
- 共享資安引擎透過 SecurityBridge 載入原租屋 includes。不得直接 include 租屋 db.php，避免混用 session、控制器或錯誤拒絕同步 PUT/DELETE。獨立 session Cookie 限定 /yc_note/。
- 授權、密碼、角色與封鎖狀態逐次重新驗證。保留 CSRF 輪換、登入限流、IP 封鎖、上傳掃描、私有儲存及安全稽核；不記錄密碼、token 或筆記內容。
- 不新增 AI 功能、外部遙測、原 Saber 更新或發佈服務。保留 GPL-3.0 與原作者授權聲明。
- 保留 package:saber、.sbn2/.sba 格式等內部相容性，不為改名而大量破壞格式。
- 修改後執行相關 PHP 整合、HTTP、唯讀與同步測試，並以 Dart analyze 檢查。iPad 實機驗證必須如實區分編譯、安裝、啟動和同步。
- 進度與未完成項目見 docs/STATUS.md；部署清單見 docs/DEPLOYMENT.md。不得把部署憑證、真實使用者畫面或測試資料提交到 GitHub。
