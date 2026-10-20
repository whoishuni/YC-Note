# iPad 免費簽署評估（2026-10-20）

使用者已停止所有續簽工作，改用 App Store 官方 Saber。使用者回覆已安裝；Apple 裝置 SDK 獨立確認 com.adilhanney.saber 1.36.2 build 136020。這條路線不使用本機 Personal Team 描述檔，無需每七天透過 Mac 續簽。GitHub IPA 仍需簽署，不能混為同一安裝方式。

AltStore 遠端刷新回報 -80045；SideStore 安裝後回報 failed to sign in，沒有成功自行刷新或接管 YC Note。以下為停止前的歷史紀錄，沒有待使用者操作的續簽任務。原 App 與筆記保留。

## 目前設定進度

- Mac 已從官方下載 AltServer，驗證程式簽章與 macOS Gatekeeper 接受後安裝，透過官方更新器升至 1.8.1（build 98），更新後再次驗證簽章。這是選單列工具，沒有一般主視窗；啟動 App 沒有出現視窗不代表安裝失敗。
- 使用者完成首次 USB 連線，Apple 裝置工具確認實體 iPadOS 18.7.8 已連線。
- 已將既有 YC Note Documents 備份到本機私有目錄，12 個檔案、6,822,306 bytes，逐檔 SHA-256 記錄，檔案權限 0600。沒有上傳備份或裝置描述檔到 GitHub／公開網站。
- 使用者回覆 iPad 已安裝，Apple 裝置工具亦確認 AltStore 2.3（build 64）與原本 YC Note 並存。AltStore 不是從 Apple App Store 下載；首次須由 Mac AltServer 安裝。後續為安裝 SideStore，依使用者明確同意只撤銷 AltStore 的舊開發憑證；保留 MacBook Pro 的 Apple Development 憑證。原 AltStore 不再可作為有效的續簽工具，尚未刪除其 App。
- 使用者已完成 Remote AltServer 配對；裝置工具確認 LocalDevVPN 1.3.0 已安裝，共用偏好設定確認 `prefersRemoteAltServer = true`。已停止 Mac AltServer 並要求拔除 USB，進行不依賴 Mac 的刷新；使用者回報 AltStore 刷新失敗：`This action cannot be completed at this time (-80045)`。遠端續簽目前未成功，YC Note 匯入、資料延續與排程亦未完成。
- 已從 SideStore 官方 release 下載 2026-10-16 nightly（commit `d97ec133719d79916675bdaf722b82a8d091d545`），SHA-256 與 GitHub asset digest 相符、ZIP 完整性通過。已由 iloader 安裝；Apple 裝置工具獨立確認版本 `0.7.0-20261016.1506+d97ec133`、bundle `com.SideStore.SideStore` 的簽署後識別，啟動命令成功。這是實驗版本，安裝與啟動並不證明自行續簽成功。
- AltServer 選單操作無法順利交接，已改採 SideStore 官方安裝文件推薦的 iloader。從其官方 release 下載 2.3.10，核對 GitHub asset SHA-256、完整 codesign 與 Gatekeeper 檢查後安裝至 `/Applications/iloader.app`。首次使用預設伺服器時出現 WebSocket reset；改用內建 Jkcoxson (Mac) 後，使用者自行完成 Apple 雙重認證，UI 確認登入成功。已使用 USB 安裝 SideStore，UI 回報安裝及配對檔放置成功，SDK 亦確認兩個配對檔在 SideStore 私有 Documents 中。沒有停用鑰匙圈或清除原配對。
- 已依所安裝版本的原始碼，在 SideStore 私有容器套用 `https://mac-ani.jkcoxson.com` 的 client_info／user_agent、對應登入 headers 與單一伺服器清單；停用預設的 on-device anisette，避免使用另一套尚未驗證的資料來源。修改前備份設定，修改後逐檔回讀核對；重新啟動後仍確認遠端模式與指定網址。沒有將 Apple 密碼、憑證或配對檔傳入 YC Note 網站。
- Mac AltServer 與 iloader 均已關閉，`pgrep` 分別確認無執行程序。目前等待使用者在 iPad SideStore 完成登入；未進行 SideStore 自行刷新，YC Note 亦尚未匯入工具。此階段不能宣稱已解決七天維護問題。
- Device Hub 明確顯示此版本 iPad 無法用目前 Mac 工具分享螢幕（要求 iPadOS 27 以上）；因此 iPad 的 Apple 登入、VPN 與設定操作由使用者直接完成，不要求為此升級系統。
- 儲存上限的未完成變更已另外保存到具名 Git stash「WIP per-account quotas before remote signing setup」，沒有部署；正式核心仍為整合版本 4。

Apple Personal Team 的裝置安裝描述檔有效七天。這是 iPadOS 執行 App 前檢查的條件，修改 YC Note 伺服器、登入期限、改用 Flutter 或 Swift 都不會延長它。網站已核准裝置的登入續期與此分開。

來源：[Apple Developer account overview](https://developer.apple.com/help/account/basics/about-your-developer-account)。

## 已測試的 AltStore Classic 2.3

官方穩定版 2.3 新增 Remote AltServer，最低 iOS 17.4；此前觀察到的 iPadOS 18.7.8 符合最低版本，實際 YC Note 安裝、續簽與資料延續仍須測試。

首次以電腦設定配對，安裝 LocalDevVPN。之後可用 Wi-Fi 與本機 VPN 續簽，不必每週連回自己的 Mac；官方目前註明不支援僅行動數據的遠端續簽。可嘗試背景重新整理與捷徑自動化，但不能保證 iPadOS 每次排程或遠端服務都成功；必要時仍須手動重新整理，過期後可能須電腦恢復。七天期限保留，只是提前重新簽署。

來源：[2.3 官方 release](https://github.com/altstoreio/AltStore/releases/tag/v2.3)、[Remote AltServers](https://faq.altstore.io/altstore-classic/remote-altservers)、[背景續簽](https://faq.altstore.io/altstore-classic/your-altstore)。

### 2026-10-20 實測阻礙與替代測試

AltStore 上游 issue #1828 有相同版本與遠端流程的 `-80045` 回報；這不等於本機配對失敗，也不能藉此斷定 Apple 帳號遭封鎖。已核對 v2.3 的 FetchAnisetteDataOperation 與其鎖定的 AltSign commit `0d3c1a3cac608e724ca0c8d7404253cd5fee9495`：遠端伺服器的 client_info 會傳入驗證資料，但 GrandSlam 登入仍使用寫死的 Mac User-Agent，未沿用伺服器回傳的 user_agent。

尚未合併的 AltStore PR #1834 提出 Windows ADI 與相符 client_info／user_agent 的修正；該 PR 明確只涵蓋 macOS 27 AltServer，沒有修正 iPad AltStore 遠端分支，不能當成本機 macOS 26.6.2 或目前 iPad 已修好的證據。這是上游提出的原因與修正方向，並非 Apple 公告。

SideStore 官方 nightly 與鎖定的 SideSign commit `752d1ef602aa792a432ffa1e71646dc1ecf7363c` 已核對具備 anisette client 與登入請求 header 的獨立設定。原先考慮的 Windows ADI 路徑未採用；本次實際採用已在 Mac iloader 完成使用者 Apple 登入的 Jkcoxson Mac 伺服器，將其 client_info 與 user_agent 一併套用至 SideStore 的資料產生與登入設定。iPad 自身登入及刷新尚未驗證。不依照討論區刪除原筆記、清除 iCloud 裝置或撤銷所有憑證。

來源：[AltStore #1828](https://github.com/altstoreio/AltStore/issues/1828)、[修正提案 #1834](https://github.com/altstoreio/AltStore/pull/1834)、[SideStore 官方 nightly](https://github.com/SideStore/SideStore/releases/tag/nightly)、[SideStore 的 client 設定](https://github.com/SideStore/SideStore/blob/d97ec133719d79916675bdaf722b82a8d091d545/SideStore/Core/Anisette/AnisetteConfigManager.swift)、[SideSign 的登入 header](https://github.com/SideStore/SideSign/blob/752d1ef602aa792a432ffa1e71646dc1ecf7363c/Sources/DeveloperPortal/Authentication.swift)。

iloader 2.3.10 更新了可用 anisette 伺服器清單，2.3.9 亦有針對 Apple 503 的伺服器變更。上游版本說明與本機實測分開記錄：本機 Mac 登入已成功，iPad 的登入與續簽仍待驗證。安裝流程已自動放置 SideStore 配對檔，因此沒有另行安裝獨立配對工具。來源：[SideStore 安裝前置需求](https://docs.sidestore.io/docs/installation/prerequisites)、[iloader 2.3.10](https://github.com/nab138/iloader/releases/tag/v2.3.10)、[iloader 2.3.9](https://github.com/nab138/iloader/releases/tag/v2.3.9)。

## 網站控制與 Apple 續簽的界線

標準 AltStore 2.3 的遠端刷新會在裝置安裝新 provisioning profiles，不必每次重新下載來源網站的 IPA。因此 YC Note 網站可以決定是否發放新版安裝檔、是否允許帳號與裝置使用／同步、唯讀或編輯，但停止下載不能保證阻止已安裝副本續簽。Apple 簽署與 YC Note 使用權限必須分開表達。

目前使用者可直接在租屋原生權限及裝置頁撤銷雲端存取；離線 App 仍受最近一次驗證後 24 小時的授權期限限制，不能宣稱離線立即撤銷。Apple 帳號、憑證與配對檔不放進 Hostinger PHP 網站。若另做自有簽署服務，需要獨立設計簽署環境與信任邊界，不能以一個網站按鈕假裝已能控制 Apple 續簽。

來源：[AltStore 2.3 的實際刷新流程](https://github.com/altstoreio/AltStore/blob/v2.3/AltStore/Operations/RefreshAppOperation.swift)、[來源與下載 URL](https://faq.altstore.io/developers/make-a-source)。

## 替代方案 SideStore

首次安裝需要電腦，後續可在裝置上續簽，亦嘗試背景重新整理。使用個人 Apple 帳號、配對檔與 LocalDevVPN，仍受七天與免費安裝數量限制。系統更新、重置及其他情況可能需要重新建立配對檔；不能視為永久免維護。

來源：[SideStore FAQ](https://docs.sidestore.io/docs/faq)、[安裝與配對限制](https://docs.sidestore.io/docs/installation/install)。

## 真正免安裝簽署期限：網頁 App

Safari 可將網站加入主畫面，網頁 App 不使用 Personal Team 安裝描述檔。現有租屋 YC Note 網站是管理／閱讀／下載入口，不是完整平板手寫編輯器；現有 Flutter App 的檔案、日誌、同步與 PDF 等模組使用 dart:io，不能只切換設定便變成網頁版。若採此路線，須另行開發並驗證手寫延遲、防誤觸、離線保存、匯入大文件與資料復原。

來源：[Apple 網頁 App 指南](https://support.apple.com/en-sg/guide/ipad/ipad8f1f7a29/ipados)、[WebKit 主畫面網頁 App](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/)。

## 如後續切換續簽工具

先核對雲端與原稿備份，實測實際簽署後 bundle ID、帳號儲存路徑、Keychain 與原 App 資料是否延續；不預設可以無損覆蓋，也不先刪既有 App。Apple 帳號登入由使用者自行完成，憑證、配對檔與帳號秘密不放進網站或 GitHub。測試需涵蓋原稿與附件、重啟、續簽及離線後重新同步，再決定是否採用。
