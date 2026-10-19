# iPad 免費簽署評估（2026-10-20）

使用者不升級付費 Apple Developer。保留原生手寫 App 是目前產品方向；此文件是調查與建議，沒有安裝 AltStore、SideStore、VPN，沒有變更 Apple 帳號或既有 App。

Apple Personal Team 的裝置安裝描述檔有效七天。這是 iPadOS 執行 App 前檢查的條件，修改 YC Note 伺服器、登入期限、改用 Flutter 或 Swift 都不會延長它。網站已核准裝置的登入續期與此分開。

來源：[Apple Developer account overview](https://developer.apple.com/help/account/basics/about-your-developer-account)。

## 建議先評估 AltStore Classic 2.3

官方穩定版 2.3 新增 Remote AltServer，最低 iOS 17.4；此前觀察到的 iPadOS 18.7.8 符合最低版本，實際 YC Note 安裝、續簽與資料延續仍須測試。

首次以電腦設定配對，安裝 LocalDevVPN。之後可用 Wi-Fi 與本機 VPN 續簽，不必每週連回自己的 Mac；官方目前註明不支援僅行動數據的遠端續簽。可嘗試背景重新整理與捷徑自動化，但不能保證 iPadOS 每次排程或遠端服務都成功；必要時仍須手動重新整理，過期後可能須電腦恢復。七天期限保留，只是提前重新簽署。

來源：[2.3 官方 release](https://github.com/altstoreio/AltStore/releases/tag/v2.3)、[Remote AltServers](https://faq.altstore.io/altstore-classic/remote-altservers)、[背景續簽](https://faq.altstore.io/altstore-classic/your-altstore)。

## 替代方案 SideStore

首次安裝需要電腦，後續可在裝置上續簽，亦嘗試背景重新整理。使用個人 Apple 帳號、配對檔與 LocalDevVPN，仍受七天與免費安裝數量限制。系統更新、重置及其他情況可能需要重新建立配對檔；不能視為永久免維護。

來源：[SideStore FAQ](https://docs.sidestore.io/docs/faq)、[安裝與配對限制](https://docs.sidestore.io/docs/installation/install)。

## 真正免安裝簽署期限：網頁 App

Safari 可將網站加入主畫面，網頁 App 不使用 Personal Team 安裝描述檔。現有租屋 YC Note 網站是管理／閱讀／下載入口，不是完整平板手寫編輯器；現有 Flutter App 的檔案、日誌、同步與 PDF 等模組使用 dart:io，不能只切換設定便變成網頁版。若採此路線，須另行開發並驗證手寫延遲、防誤觸、離線保存、匯入大文件與資料復原。

來源：[Apple 網頁 App 指南](https://support.apple.com/en-sg/guide/ipad/ipad8f1f7a29/ipados)、[WebKit 主畫面網頁 App](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/)。

## 如後續切換續簽工具

先核對雲端與原稿備份，實測實際簽署後 bundle ID、帳號儲存路徑、Keychain 與原 App 資料是否延續；不預設可以無損覆蓋，也不先刪既有 App。Apple 帳號登入由使用者自行完成，憑證、配對檔與帳號秘密不放進網站或 GitHub。測試需涵蓋原稿與附件、重啟、續簽及離線後重新同步，再決定是否採用。
