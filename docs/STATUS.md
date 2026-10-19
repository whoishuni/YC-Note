# YC Note 進度（2026-10-19）

## 已完成

- Fork：`whoishuni/YC-Note`；本機位於相鄰的獨立 YC-Note 目錄。
- App 名稱、YC 圖示、iOS/macOS bundle ID 與 Android application ID 已替換。
- 正式網站 https://yckuo.com/yc_note/ 已部署；9 張獨立 YC Note 資料表已建立。
- 共用管理員名單、角色映射、筆記專用使用者新增／移除／恢復、唯讀與角色管理。
- 裝置申請、人工核准、撤銷；權杖以雜湊保存。
- 版本與刪除記錄、下載／還原 API、內容雜湊／版本同步與衝突完整備份。
- 共享租屋資安引擎：輸入偵測／分類、密碼規則、CSRF、CSP、IP 黑名單與上傳掃描；另有獨立 Cookie、閒置／絕對逾期、session 輪換、限流與稽核。
- 已停用原 Saber 的遙測、更新目標與自動發佈工作流程。
- 網站與 App 文案已精簡，沒有標語或歡迎段落。

## 已驗證

- PHP service：74 項整合檢查通過。
- HTTP／網站表單：55 項整合檢查通過，含筆記專用帳號新增、移除、恢復與既有 token 的唯讀限制。
- Flutter：31 項同步、唯讀、本機檔案與附件清理檢查通過。
- Dart analyze：`lib` 與 `packages/sbn` 無問題。
- 正式站公開頁 200；未登入 API 401；私有程式與設定不在公開目錄。
- 已偵測實體 iPad 透過 USB 連線，iPadOS 18.7.8。
- Xcode 27.1 已成功編譯 iOS release，未簽署的 `Runner.app` 約 53.3 MB。

## 進行中

- Xcode 27.1 與 CocoaPods 已安裝，iOS SDK 可用；iPad 開發者模式已開啟。
- Apple 帳號姓名與 Personal Team 名稱不一致；Apple 支援更正草稿已填妥，等待使用者授權送出，尚未更名。
- Apple 帳號簽署、iPad 安裝、啟動與實際筆記上下載驗證尚未完成。
- 尚未提供可直接安裝且已簽署的 IPA。

## 後續

依 `GOODNOTES-ROADMAP.md` 逐項補齊非 AI 功能。不要把本輪的基礎建置稱為已達 Goodnotes 全功能。

部署檔案與權限設計見 `DEPLOYMENT.md`。正式憑證與真實使用者畫面不在 Git 內。
