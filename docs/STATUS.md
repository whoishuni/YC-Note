# YC Note 進度（2026-10-19）

## 已完成並部署

- Saber fork：`whoishuni/YC-Note`；名稱 YC Note、YC 圖示與各平台識別已替換。
- 租屋系統原生管理頁 https://yckuo.com/rent/admin/notes.php，共用登入、Session、header/footer、選單與 CSRF；舊 `/yc_note/` 導向此頁。
- 只使用租屋管理員帳號；超級管理員預設開放，一般管理員與舊 manager 別名須明確授權。租客、代登入、未授權管理員均無法存取。
- 未開放／唯讀／可編輯三種權限；帳號新增刪除沿用租屋流程。原筆記專用帳號停止登入，歷史資料保留。
- 裝置人工核准、撤銷、個人裝置自助撤銷、超級管理員安全稽核。
- 已核准 App 登入可自動續期至 90 天後；每次請求仍檢查帳號、密碼、角色、裝置與權限。未核准、已過期及已撤銷憑證不會自動復活。
- 個人儲存用量、檔案搜尋與分頁、已刪除篩選、歷史版本下載。管理員只能下載自己的內容。
- 新舊 bearer API 共用資料與授權；舊 App 無需改網址。共享 IP 黑名單、地區限制、輸入偵測、上傳掃描與維護模式。
- YC Note 私有資料庫 9 張表；私有版本程式、憑證與筆記都在 public_html 外。
- 同步的版本比較、衝突完整備份、編輯途中改唯讀的「本機保留」與離線限時授權。

## 已驗證

- PHP 服務：85 項；API HTTP：38 項。
- 租屋原生 Session／CSRF／控制器／header/footer：55 項；共用安全攔截畫面：53 項。
- 先前 Flutter 35 項檢查及 Dart analyze 通過。本次修改僅 PHP、網站與部署，未變更已安裝的 Flutter 程式。
- 正式站舊管理頁 302 轉入原生頁；未登入原生頁導回租屋登入。新舊 API 皆 401 JSON，精確新 API 路徑的 PUT／DELETE 能通過 Apache 方法閘門並要求認證。
- 正式站 boot.php 與 .local-root.php 直接請求 403；新 CSS 200；臨時部署入口已移除。
- Xcode 27.1 release 簽署、完整簽章驗證、安裝到實體 iPad 與 CLI 啟動均成功。

程式碼審閱：[YC Note #1](https://github.com/whoishuni/YC-Note/pull/1)、[租屋整合 #35](https://github.com/whoishuni/Rental-management-system/pull/35)。

## 仍待驗證／開發

- 正式租屋登入後的管理頁實際帳號驗證，等待使用者完成登入。
- iPad 真實手寫、PDF、重啟與雲端往返；不可將編譯／安裝／啟動當成同步驗收。
- 免費 Personal Team 簽署到 2026-10-26，與網站自動續期是不同限制。Apple 更名草稿依使用者決定關閉，未送出。
- 整本筆記原子版本、分塊續傳、可驗證完整備份／還原、配額管理、更多 Goodnotes 非 AI 功能，見 GOODNOTES-ROADMAP.md。

正式部署使用租屋整合工作樹，沒有覆蓋原 checkout 的其他未提交財務修改。憑證、真實使用者畫面與含裝置資訊的 IPA 不提交 GitHub。
