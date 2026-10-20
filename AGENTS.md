# YC Note 工作規則

- 使用者最新決定：iPad 改用 App Store 官方 Saber（com.adilhanney.saber），透過 Nextcloud 相容協定連入本後台；停止 AltStore／SideStore 續簽工作。保留 fork 與舊筆記，不再預設重新簽署或安裝 YC Note。後端與管理功能深度整合租屋管理系統，包含共用登入／session、原生選單、角色權限、裝置、儲存、稽核與維運。不得只新增外連或 iframe。租屋整合工作樹為 `/Users/yckuo/.codex/worktrees/yc-note-rental-integration/Rental-management-system`，原租屋 checkout 有其他未提交工作，不得覆蓋。
- 正式網址：https://yckuo.com/yc_note/。筆記資料庫為 u805049489_ycnote；筆記與憑證必須位於 public_html 外。
- UI 比照租屋系統：簡潔、乾淨、有質感。網站與 App 都只保留名稱、必要狀態、操作與錯誤訊息。禁止標語、歡迎文案、宣傳段落、技術堆砌、裝飾性說明、Emoji。
- 網站名稱 YC Note；保留 fork 的紫色 YC 圖示與原生 SVG。官方 App 名稱與圖示維持 Saber，不能由伺服器更改。
- 只使用租屋管理員帳號：super_admin 預設有筆記權限；admin 與舊 manager 別名須明確授權。租客不可使用或看見筆記入口。關閉筆記專用帳號的新增與登入，保留歷史資料，不自動轉移所有權。帳號建立／刪除使用租屋原生流程，筆記入口不另設登入頁。
- 管理權限由超級管理員控制；明確區分未開放、唯讀與可編輯。選單、頁面與 App API 都檢查同一來源，不能只隱藏入口。普通管理員只存取自己的筆記，不因此取得他人資料與授權管理權限。
- 移除編輯權限變成雲端唯讀：仍可下載，伺服器禁止新增、修改、刪除、還原。官方 Saber 的本機編輯與離線使用不能遠端鎖定；舊 YC Note 自訂客戶端保留原限制，不宣稱兩者相同。
- 每次官方 App 新連線須明確核准，超級管理員可在原生授權頁人工核准自己的連線；一般管理員送出申請。這是每組連線憑證，並非硬體認證；撤銷不會刪除裝置已下載的資料。
- 使用者不升級付費 Apple Developer，改用官方 App Store 版免除自行續簽。保留原 YC Note／AltStore／SideStore，未授權刪除資料或再撤銷 Apple 憑證。
- 管理頁使用租屋 db.php、auth.php、CSRF 與原生 header/footer。App API 維持 bearer／裝置認證並共用資安引擎，勿誤套只接受 GET/POST 的網頁方法限制。舊 `/yc_note/api.php` 需保留相容入口，避免已安裝 iPad 因重新導向而停止同步；舊管理頁導向原生租屋模組。
- 授權、密碼、角色與封鎖狀態逐次重新驗證。保留 CSRF 輪換、登入限流、IP 封鎖、上傳掃描、私有儲存及安全稽核；不記錄密碼、token 或筆記內容。
- 自訂程式不新增 AI、外部遙測或原 Saber 發佈服務；官方 App 的上游行為與隱私政策不由本 fork 控制。保留 GPL-3.0 與原作者授權聲明。
- 官方 Saber 端對端加密檔名與內容。與舊明文筆記分開儲存，網站不得假裝能直接預覽／轉 PDF；不收集或保存加密密碼。保留舊 SBA／PDF，網站已加入瀏覽器內解鎖、逐頁預覽、PDF／原稿下載；密碼與明文不能傳伺服器。維持錯誤密碼、跨帳號、鎖定後非同步復活、世代與附件完整性檢查。PDF 是頁面影像，不能宣稱含可選文字或原互動連結。
- 保留 package:saber、.sbn2/.sba 格式等內部相容性，不為改名而大量破壞格式。
- 修改後執行相關 PHP 整合、HTTP、唯讀與同步測試，並以 Dart analyze 檢查。iPad 實機驗證必須如實區分編譯、安裝、啟動和同步。
- 進度與未完成項目見 docs/STATUS.md；部署清單見 docs/DEPLOYMENT.md。不得把部署憑證、真實使用者畫面或測試資料提交到 GitHub。
