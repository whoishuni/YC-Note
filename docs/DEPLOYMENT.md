# YC Note 部署

管理入口：https://yckuo.com/rent/admin/notes.php。已完成部署及建表，不需要手動匯入 SQL。舊 https://yckuo.com/yc_note/ 轉入租屋系統。

## 檔案清單

| 位置 | 內容 |
| --- | --- |
| `public_html/rent/admin/notes.php` | 原生管理控制器 |
| `public_html/rent/includes/YcNoteModule.php` | 租屋 Session、角色與私有核心介接 |
| `public_html/rent/includes/yc_note_view.php` | 簡潔管理介面 |
| `public_html/rent/assets/css/yc-note.css` | 管理頁樣式 |
| `public_html/rent/yc_note/api.php` | 新 bearer API |
| `public_html/yc_note/` | 舊管理頁相容轉入、既有 API、boot、圖示與安全設定 |
| `/home/u805049489/yc-note/releases/<版本>/` | 私有 PHP 核心、設定副本、部署前檔案備份 |
| `/home/u805049489/yc-note/config.local.php` | 正式憑證，0600 |
| `/home/u805049489/yc-note/blobs/` | 私有筆記與歷史版本 |
| `/home/u805049489/yc-note/active-release` | 目前私有版本 |

租屋原檔另有四項小幅修改：`config/features.php` 加入開關、`includes/admin_header.php` 加入授權選單、`includes/MaintenanceMode.php` 提供同一維護判斷給 API、`.htaccess` 對精確 API 路徑保留方法驗證。不要以整份舊檔覆蓋正式站的其他變更。

`public_html/yc_note/` 保留 `index.php`、`api.php`、`boot.php`、`style.css`、`icon.svg`、`.htaccess`、`.local-root.php`。後兩個 PHP 輔助入口直接 HTTP 請求須 403。真正的憑證、私有原始碼與備份不得放在 public_html。

## 帳號與資料庫

YC Note 保留 11 張表：owners、devices、tokens、files、revisions、audit、rate_limits、note_accounts、access_rules、sync_staging、sync_commits。note_accounts 僅保留歷史資料，不再登入或新增。

租屋 `users` 為唯一帳號來源。`super_admin` 預設開放；`admin`／歷史 `manager` 需在筆記權限頁授權。租客不可開放，代登入也不能看私人筆記。筆記權限不改動租屋角色；新增／刪除使用者沿用租屋原生安全流程。

租屋 SQL 僅新增 `yc_note_identity` 檢視，沒有改寫原帳號；另以唯讀查詢沿用 IP 黑名單及 system_settings 維護狀態。正式身分連線沿用租屋 SQL 憑證，連線設 `SET SESSION TRANSACTION READ ONLY`；SQL 帳號本身仍保有原租屋權限。可進一步改為僅 SELECT 身分 view、ip_blacklist 與 system_settings 的專用帳號。筆記 DB 使用獨立 `u805049489_ycnote`。

## 更新與回復

`deploy_hostinger.py` 透過 stdin 取得短期上傳憑證與租屋工作樹的 `rental_root`。不傳 db_password 時讀取本機私有 `~/.config/yc-note/deploy.json`。不在指令列或日誌輸出秘密。

部署先建立私有版本與冪等建表，驗證精確檔案清單，再以帶唯一上下文的插入修補租屋既有檔，保留其他正式站修改。原檔備份到私有版本的 rental-backup；最後切換版本、更新舊入口並移除臨時安裝器。捕捉發布錯誤時恢復已更新檔案與前一 active-release。

若手動打包，執行：

```sh
python3 scripts/yc_note/build_server_bundle.py --rental-root /absolute/path/Rental-management-system
```

套件內有公開新檔、私有核心與租屋修補檔；不包含正式憑證。保留既有 blobs、config.local.php 與歷史資料。完整備份需同時涵蓋 SQL、blobs 與設定；目前沒有自動刪除歷史版本工作，歷史版會計入配額。完整還原演練仍待完成。

## 筆記與 PDF

網站以整本筆記呈現，附件不獨立列出。原稿下載為 SBA，包含主檔及所有編號附件，封面與生成的 PDF 不加入原稿壓縮檔。匯出暫存放在私有 exports，請求結束清除；突然中止 PHP 程序留下的匯出暫存仍需維運清理。

PDF 由更新後的 App 在列表同步時預先生成，網站不直接執行 Flutter 筆跡轉換。`.sbn2.pdf.json` 記錄完整原稿與編號附件雜湊及 PDF 雜湊，符合目前版本才可預覽／下載；PDF 尚未生成或失效時顯示「PDF 待更新」。下載仍重新驗證所有權與內容完整性，不接受跨帳號參數。

`stage` 上傳不更動可見筆記，`commit` 以帳號快照與各檔基準版本比對後，單次交易發布所有修改並保留收據 7 天。待提交內容保留約 24 小時；未使用收據過期後仍依內容與版本重新同步。帳號首次採用原子同步後，舊逐檔寫入回覆 426，其他裝置須更新 App。

本機 `.yc-local-batch` 先保存修改前後內容，再發布整批原稿／附件／基準。啟動先恢復日誌；隱藏日誌、暫存與衝突備份不出現在一般筆記列表。伺服器原子提交與整本歷史還原不同，目前歷史頁仍列主檔版本。

## 裝置與登入

App 首次人工核准；已核准且未過期的登入成功驗證後延至 90 天後，無需定期人工核准。90 天無成功驗證則需重新登入，原安裝的裝置核准保留。修改密碼、角色、封鎖、撤銷或移除筆記存取權會使既有憑證失效。原生網站 Session 保留租屋系統的逾期規則。

離線授權為最近一次驗證後 24 小時；裝置離線時不能瞬間撤銷。移除編輯權限變成唯讀，完整移除存取權則禁止存取。App 本機保存待同步內容，編輯中降權時保留完整復原副本。

## iPad

PDF 功能版已更新安裝，最新 PDF 同步仍待實機驗證；已配對的 iPad 可經 localNetwork 無線安裝。原生依賴採 CocoaPods，先 flutter pub get，再於 ios/ 執行 pod install；Rust 需 1.97.1 與 aarch64-apple-ios target。iOS bundle ID 為 com.yckuo.ycnote。Flutter/Dart 仍須 Apple Xcode 編譯簽署。

免費 Personal Team 安裝簽署為 7 天；付費 Apple Developer 年費 US$99，地方售價以申請畫面為準，可使用較長效期的裝置簽署且不必上架。實際到期時間依簽發 profile 確認，網站不能延長 Apple 簽章效期。

[Apple 帳號限制](https://developer.apple.com/help/account/basics/about-your-developer-account)、[會員費用](https://developer.apple.com/help/account/membership/program-enrollment/)、[Flutter iOS 部署](https://docs.flutter.dev/deployment/ios)。
