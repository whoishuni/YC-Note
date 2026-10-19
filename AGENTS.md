# YC Note 工作規則

- 這是 Saber fork 的 YC Note App。使用者最新決定：後端與管理功能深度整合租屋管理系統，包含共用登入／session、原生選單、角色權限、裝置、儲存、稽核與維運。不得只新增外連或 iframe。租屋整合工作樹為 `/Users/yckuo/.codex/worktrees/yc-note-rental-integration/Rental-management-system`，原租屋 checkout 有其他未提交工作，不得覆蓋。
- 正式網址：https://yckuo.com/yc_note/。筆記資料庫為 u805049489_ycnote；筆記與憑證必須位於 public_html 外。
- UI 比照租屋系統：簡潔、乾淨、有質感。網站與 App 都只保留名稱、必要狀態、操作與錯誤訊息。禁止標語、歡迎文案、宣傳段落、技術堆砌、裝飾性說明、Emoji。
- 名稱 YC Note；紫色漸層 YC 圖示，不含房屋。圖示以 repo 原生 SVG 維護。
- 只使用租屋管理員帳號：super_admin 預設有筆記權限；admin 與舊 manager 別名須明確授權。租客不可使用或看見筆記入口。關閉筆記專用帳號的新增與登入，保留歷史資料，不自動轉移所有權。帳號建立／刪除使用租屋原生流程，筆記入口不另設登入頁。
- 管理權限由超級管理員控制；明確區分未開放、唯讀與可編輯。選單、頁面與 App API 都檢查同一來源，不能只隱藏入口。普通管理員只存取自己的筆記，不因此取得他人資料與授權管理權限。
- 移除編輯權限必須變成唯讀：仍可閱讀和下載，禁止新增、修改、刪除、還原。App 編輯器、本機檔案操作與 API 寫入端都要落實，不能只隱藏按鈕。
- 每台 App 裝置須管理者核准。裝置撤銷與帳號移除會撤銷伺服器存取。離線驗證有期限，不能宣稱離線裝置可瞬間撤銷。
- 使用者明確不升級付費 Apple Developer。免費 Personal Team 七天簽署與 YC Note 登入核准分開處理；優先評估免費自動續簽，不能宣稱消除 Apple 期限。安裝工具尚未切換，方案與來源見 docs/IOS-SIGNING.md。
- 管理頁使用租屋 db.php、auth.php、CSRF 與原生 header/footer。App API 維持 bearer／裝置認證並共用資安引擎，勿誤套只接受 GET/POST 的網頁方法限制。舊 `/yc_note/api.php` 需保留相容入口，避免已安裝 iPad 因重新導向而停止同步；舊管理頁導向原生租屋模組。
- 授權、密碼、角色與封鎖狀態逐次重新驗證。保留 CSRF 輪換、登入限流、IP 封鎖、上傳掃描、私有儲存及安全稽核；不記錄密碼、token 或筆記內容。
- 不新增 AI 功能、外部遙測、原 Saber 更新或發佈服務。保留 GPL-3.0 與原作者授權聲明。
- 保留 package:saber、.sbn2/.sba 格式等內部相容性，不為改名而大量破壞格式。
- 修改後執行相關 PHP 整合、HTTP、唯讀與同步測試，並以 Dart analyze 檢查。iPad 實機驗證必須如實區分編譯、安裝、啟動和同步。
- 進度與未完成項目見 docs/STATUS.md；部署清單見 docs/DEPLOYMENT.md。不得把部署憑證、真實使用者畫面或測試資料提交到 GitHub。
