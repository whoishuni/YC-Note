# YC Note 部署

管理入口：https://yckuo.com/rent/admin/notes.php。已完成部署及建表，不需要手動匯入 SQL。舊 https://yckuo.com/yc_note/ 轉入租屋系統。

## 官方 Saber 相容服務（整合版本 5）

iPad 使用 App Store 官方 Saber，在自訂 Nextcloud 伺服器填入 `https://yckuo.com/rent/yc_note/saber`。瀏覽器開啟租屋登入及 `admin/notes_connect.php` 的 CSRF 核准頁，不把租屋密碼交給 App。已核准的每組連線取得獨立雲端憑證；首次登入流程 20 分鐘失效，憑證只交付一次，活躍時延長 90 天閒置期限。使用者自行在 App 輸入加密密碼，伺服器不保存。

新增 `rent/yc_note/saber.php` 受限路由控制器與 `saber/{index.php,remote.php,status.php,ocs/v1.php,ocs/v2.php,.htaccess}` 包裝入口；實際協定控制器在私有 release 的 `public/saber.php`，核心在 `src/SaberCloud.php`。只開放精確 DAV 路徑的方法，不對租屋其他路徑開放 PUT／DELETE。相容 Saber 1.36.2 的 Nextcloud 9.0.0 SDK，並非完整 Nextcloud 服務。

新增 5 張表 saber_login_flows、saber_credentials、saber_heads、saber_nodes、saber_revisions，合計 17 張；冪等建表保留舊資料。新版管理頁與核心須一起部署。每次雲端認證重新驗證租屋密碼雜湊、角色、封鎖、啟用、筆記權限及連線狀態；撤銷同時使未消費的舊授權失效，重新核准不復活舊憑證。

官方版的 `.sbe` 檔案與檔名皆為密文，`config.sbc` 保留加密金鑰封套；與原明文 `files` 分開，歷史版本共同計入配額。網站「Saber 雲端」可下載同一帳號世代的加密 ZIP 備份，包含設定、密文與雜湊 manifest；這不是可直接匯入 Saber 的 SBA，也不是已完成的完整還原流程。網站目前不解密或轉換這些檔案；PDF 在 Saber 匯出，舊 YC Note 的網站 PDF 功能保留。官方版的離線／本機編輯、圖示名稱不能由後台更改；雲端唯讀只限制伺服器寫入。

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

舊版 YC Note 原有 12 張表：owners、devices、tokens、files、revisions、audit、rate_limits、note_accounts、access_rules、sync_staging、sync_commits、notebook_versions。note_accounts 僅保留歷史資料，不再登入或新增。整本版本功能需要私有核心整合版本 4 與配套租屋檔案一起發布。

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

## 原 YC Note 自訂客戶端的筆記與 PDF

網站以整本筆記呈現，附件不獨立列出。原稿下載為 SBA，包含主檔及所有編號附件，封面與生成的 PDF 不加入原稿壓縮檔。匯出暫存放在私有 exports，請求結束清除；突然中止 PHP 程序留下的匯出暫存仍需維運清理。

PDF 由更新後的 App 在列表同步時預先生成，網站不直接執行 Flutter 筆跡轉換。`.sbn2.pdf.json` 記錄完整原稿與編號附件雜湊及 PDF 雜湊，符合目前版本才可預覽／下載；PDF 尚未生成或失效時顯示「PDF 待更新」。下載仍重新驗證所有權與內容完整性，不接受跨帳號參數。

`stage` 上傳不更動可見筆記，`commit` 以帳號快照與各檔基準版本比對後，單次交易發布所有修改並保留收據 7 天。待提交內容保留約 24 小時；未使用收據過期後仍依內容與版本重新同步。帳號首次採用原子同步後，舊逐檔寫入回覆 426，其他裝置須更新 App。

`notebook_versions` 保存每次原子提交的整本原稿及編號附件關係，不含衍生 PDF／封面。首次遷移只保存當下實際內容；既有逐檔歷史無法可靠推測當時附件配對，仍保留為「主檔記錄」。網站歷史原稿下載為該版本完整 SBA。還原與移至垃圾桶採同一 SQL 交易及整本指紋檢查；舊表單回覆 409 並重新顯示目前資料，唯讀不可操作。還原使原有 PDF／封面失效，由 App 後續同步重新生成。刪除仍保留版本並計入配額；目前沒有永久清除。

本機 `.yc-local-batch` 先保存修改前後內容，再發布整批原稿／附件／基準。啟動先恢復日誌；隱藏日誌與暫存不出現在一般筆記列表。新 App 的完整衝突副本顯示在「同步衝突備份」，並搬移舊隱藏完整副本；此資料夾不參與雲端同步或批次 PDF 生成。副本可在 App 開啟，使用者要採用時可透過檔案操作移出此資料夾。

## 原 YC Note 自訂客戶端的裝置與登入

App 首次人工核准；已核准且未過期的登入成功驗證後延至 90 天後，無需定期人工核准。90 天無成功驗證則需重新登入，原安裝的裝置核准保留。修改密碼、角色、封鎖、撤銷或移除筆記存取權會使既有憑證失效。原生網站 Session 保留租屋系統的逾期規則。

離線授權為最近一次驗證後 24 小時；裝置離線時不能瞬間撤銷。移除編輯權限變成唯讀，完整移除存取權則禁止存取。App 本機保存待同步內容，編輯中降權時保留完整復原副本。

## 原 YC Note 的 iPad 安裝（歷史紀錄）

PDF 功能版已更新安裝，使用者回覆「看過沒問題」，記為手動確認；沒有再次要求裝置驗證。已配對的 iPad 可經 localNetwork 無線安裝。新衝突副本可見與遷移功能尚未安裝到實機。原生依賴採 CocoaPods，先 flutter pub get，再於 ios/ 執行 pod install；Rust 需 1.97.1 與 aarch64-apple-ios target。iOS bundle ID 為 com.yckuo.ycnote。Flutter/Dart 仍須 Apple Xcode 編譯簽署。

使用者不升級付費 Apple Developer。免費 Personal Team 安裝簽署為 7 天，實際到期時間依簽發 profile 確認；網站不能延長 Apple 簽章效期。免費遠端續簽方案與尚未執行的切換步驟見 IOS-SIGNING.md。

2026-10-20 私有 IPA 已編譯並驗證完整簽章，尚未再次安裝；profile 仍到期於 2026-10-26 06:13:27 UTC。IPA 含裝置描述檔，不提交 GitHub，也不混入伺服器套件。伺服器 ZIP 與部署收據改以實際當日日期命名。

[Apple 帳號限制](https://developer.apple.com/help/account/basics/about-your-developer-account)、[會員費用](https://developer.apple.com/help/account/membership/program-enrollment/)、[Flutter iOS 部署](https://docs.flutter.dev/deployment/ios)。
