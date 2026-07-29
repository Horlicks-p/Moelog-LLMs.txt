# MoeLog LLMs.txt 修復計畫

## 目標

先修正可能公開受保護內容的問題，再改善 `/llms.txt` 的效能、網址解析與 Markdown 正確性。這是個人用的小型外掛，因此維持目前三個 PHP 檔案的簡單結構，不為了形式加入設定頁、資料表或大型相依套件。

## 第一階段：安全性與公開內容邊界（必要）

### 1. 排除密碼保護文章

- 在 `serve_markdown()` 取得 `WP_Post` 後，若 `post_password` 非空，直接回傳 404。
- 不採用「已輸入正確密碼即可輸出 Markdown」的方式，避免受保護內容被 `Cache-Control: public` 或 CDN 共用快取。
- `get_posts()` 加入 `has_password => false`。
- `get_pages()` 的結果在輸出前排除 `post_password` 非空的頁面。
- 404 回應統一加入 `X-Robots-Tag: noindex` 與不可快取標頭。

驗收：

- 公開文章的 `.md` 正常輸出。
- 密碼保護文章即使狀態為 `publish`，`.md` 仍回傳 404，且不出現在 `/llms.txt`。
- 草稿、私密文章與非 `post`／`page` 內容仍不可讀取。

### 2. 收斂請求判斷

- 不再用 PHP 真值判斷 Markdown slug，讓 slug 為 `0` 時也能正確處理。
- 僅處理預期的 GET／HEAD 請求；HEAD 不產生不必要的完整 response body。

## 第二階段：效能與網址正確性（建議一起完成）

### 3. 快取 `/llms.txt`

- 用 transient 快取完成的索引文字，預設保存 30 分鐘。
- HTTP 標頭改成 `Cache-Control: public, max-age=1800`。
- 在文章或頁面儲存、刪除、進出垃圾桶及狀態改變時清除 transient；失效策略採「寧可多刪」，`save_post` 不必特別排除 revision 或 autosave，因為多一次 `delete_transient()` 的成本遠低於留下過期索引。
- 站名、網站描述或固定網址結構改變時也要清除 transient，掛入 `update_option_blogname`、`update_option_blogdescription` 與 `update_option_permalink_structure`。
- 所有失效 hooks 一律掛到外掛自己的無參數 `flush_index_cache()`，由它刪除固定 transient key；不可直接把 `delete_transient()` 當 callback，否則 WordPress 傳入的 `$post_id`／`$old_value` 會被誤當成 transient key。
- 查詢固定保留完整 `WP_Post` 物件，並設定 `no_found_rows => true`、`update_post_meta_cache => false`、`update_post_term_cache => false`。不使用 `fields => ids`，因為它不會填入 post object cache，後續逐篇呼叫 `get_permalink()`／`get_post()` 會造成 N+1 查詢。
- 索引日期直接由已載入的 `$post->post_date` 經 `mysql2date( 'Y-m-d', ... )` 產生，不再逐篇呼叫 `get_the_date()`。
- 保留「列出全部文章」作為個人站預設；若內容量明顯成長，再加數量上限或可篩選的精選清單。

驗收：

- 連續請求 `/llms.txt` 時，第二次不再執行完整文章查詢。
- 發佈、更新、刪除文章後，origin 的下一次請求立即重建索引；已由 CDN 依 `max-age` 快取的副本允許在 30 分鐘內更新。
- 快取內容不包含密碼保護文章。

### 4. 明確處理固定網址模式

- 偵測 `permalink_structure` 是否為空。
- 使用漂亮網址時，維持目前的 `/slug.md` 形式。
- 使用 Plain Permalinks 時，不產生 `?p=123.md` 這種無效網址；`/llms.txt` 改列原始 HTML permalink，並在檔頭說明本站未提供 `.md` 版本。
- README 明確標示 `.md` 功能需要非 Plain Permalinks。
- 將「啟用後重新儲存固定網址」改成遇到 404 時的疑難排解步驟，因為 activation hook 已經 flush rewrite rules。

### 5. 修正文章解析順序

- 保留 `url_to_postid()` 作為首選。
- 之後先用完整路徑查詢巢狀頁面，再考慮最後一段 slug；保留最後一段 fallback，以免某些自訂 permalink 結構在 `url_to_postid()` 失手時回歸成 404。
- 不論候選 ID 來自哪一種解析方式，回傳前都用 `get_permalink()` 反查並正規化 URL path；只有候選 permalink path 與請求的 slug path 相同時才接受。比較前兩邊都必須先經 `rawurldecode()`，並一致處理網站子目錄、尾端斜線及 URL encoding，避免中文 slug 全部誤判為 404。
- 這道 canonical path 驗證是必要防線，避免 `/random/path/existing-slug.md` 因最後一段相同而誤中別篇文章。

驗收：

- 巢狀頁面 `/parent/child.md` 命中正確頁面。
- `/random/path/existing-slug.md` 不會意外回傳另一篇文章。
- 日期型、分類型及文章名稱型固定網址各測一例。
- 中文 slug（包括 permalink 為百分比編碼與原始 UTF-8 兩種輸入）必須命中同一篇文章。

## 第三階段：Markdown 轉換可靠性（重要但可分次做）

### 6. 加入依情境使用的 Markdown 跳脫

- 建立小型 helper，分別處理一般文字、連結文字、圖片 alt、URL、表格儲存格與程式碼；不要用同一套替換規則處理所有情境。
- 正文的一般文字節點與摘要、作者、分類、標籤、日期等散文／資料欄位不做全面 Markdown 跳脫，避免把版本號、日期、`C++` 或一般標點變成滿版反斜線。結構跳脫只用於連結文字、圖片 alt、表格 cell、H1／站名等結構行，以及清單項目行首。
- 標題與分類名稱先去除 HTML、解碼 entity，再跳脫 Markdown 控制字元。
- 表格 cell 將 `|` 轉成 `\|`，換行正規化為 `<br>` 或空白。
- 行內 `<code>` 使用 `nodeValue`，並依內容中最長的反引號序列選擇外層 delimiter。
- fenced code block 若內容含有三個反引號，改用更長 fence。
- 表格只處理目前表格的直接列，避免外層表格重複收進巢狀表格的 `<tr>`。

建議測試案例：

- 標題含 `[ ] ( ) &`。
- 連結文字及圖片 alt 含中括號。
- 表格內容含 `|`、換行與巢狀表格。
- 行內 code 及 code block 含反引號。
- 巢狀清單、blockquote、figure／figcaption。

### 7. 強化 DOMDocument 失敗處理

- 使用 `class_exists( 'DOMDocument' )` 檢查 ext-dom；缺少時安全降級為純文字，不讓端點 Fatal Error。
- 保存 `libxml_use_internal_errors()` 原始值，並以 `try/finally` 清除錯誤及還原全域設定。
- 移除目前用來提示 UTF-8、但格式不完整的 `<?xml encoding="UTF-8">` 偽 processing instruction。改載入完整的 `<html><head><meta charset="UTF-8"></head><body>...` wrapper，拿掉與完整 wrapper 用法衝突的 `LIBXML_HTML_NOIMPLIED`，再用 `DOMXPath` 取得內容節點。
- 不依賴 `getElementById()` 搭配 `LIBXML_HTML_NODEFDTD` 找 wrapper；針對目前支援的 libxml/PHP 環境加測試，解析失敗時才降級為純文字。
- 圖片優先使用有效的 `src`，必要時再依序嘗試 `data-src`、`data-lazy-src`，但不把透明 placeholder 當成正文圖片。

### 8. 對稱還原內容處理狀態

- 對原始 global post 與 postdata 做完整、對稱的保存與還原。
- `try/finally` 必須實際包住 `apply_filters( 'the_content', ... )`；即使第三方 callback 丟出例外，也要還原 global post、postdata 與被移除的 callbacks。
- 不只手動還原 `$GLOBALS['post']`；需同時考慮 `setup_postdata()` 影響的相關 globals。
- `suspend_noise_filters()` 先完整收集要移除的 callbacks，再執行移除，不在遍歷 `$wp_filter['the_content']->callbacks` 時同步修改它。
- 連續產生兩次 Markdown 後，`the_content` 的 callback 數量、callable、priority 與 accepted args 必須與處理前完全一致，不能累積或遺失。

## 第四階段：可維護性與文件（有需要再做）

### 9. 收斂噪音 filter

- 將 `noise_patterns` 暴露為 `moelog_llms_noise_patterns` filter，README 改教使用者在自有小外掛或 mu-plugin 設定，不再直接修改此外掛檔案。
- 移除過寬的 `jetpack`、`wpp_` 模糊比對，改成已確認的精確 callable 或較嚴格的類別／方法規則。
- 收斂這些 patterns 會改變既有輸出，可能讓原本被擋下的 Jetpack 分享按鈕或相關文章重新出現；修改前後須用本站同一篇實際文章做 Markdown diff，確認正文沒有多出噪音，也沒有少掉圖庫、shortcode 等有效內容。
- 可提供 `moelog_llms_txt_output` 與 `moelog_llms_post_types` filter，但只在確有 CPT 或自訂輸出需求時加入。

### 10. 修正文案與協定宣稱

- 將「llms.txt compliant」改成「依循 llms.txt 提案格式」，因為目前 `/about.md` 是 WordPress 友善變體，並非提案對目錄 URL 建議的 `index.html.md`。
- H1 後只保留一段網站摘要 blockquote；目前「本文件遵循規範」及 `.md` 使用說明改成普通段落，不再形成第二段 blockquote。
- `robots.txt` 的 `LLMs-txt:` 是非標準 directive；移除它，或只保留不會被誤認為規則的註解。
- `<link rel="llms-txt">` 可保留為無害提示，但 README 不宣稱所有 AI crawler 都會辨識。
- 外掛 header 補上實際支援的 `Requires at least` 與 `Requires PHP`；因計畫使用 `try/finally`，本外掛明確以 PHP 7.4 以上為支援基線。
- README 說明 ext-dom 為建議 extension，以及缺少時會降級成純文字。

## 獨立可選階段：提早攔截請求

- 評估將端點處理由 `template_redirect` 提前到 `parse_request`，避免 AI crawler 請求已知端點時仍執行不需要的主查詢。
- 此變更會影響 hook 時機、query vars、第三方 filter 與 canonical redirect 的互動，必須獨立提交、獨立做相容性測試，不和密碼外洩修復或快取改動混在一起。
- 只有在量測確認主查詢成本值得處理後才實作；目前不是安全修復的阻擋項目。

## 暫不納入

以下項目對目前的個人外掛收益有限，先不增加維護成本：

- WordPress.org 專用 `readme.txt`：只有準備提交官方目錄時再補。
- 各目錄空白 `index.php`：目前 PHP 檔已有直接存取防護，也沒有敏感資料檔。
- 完整 i18n：純自用且介面只有輸出文字，等需要公開給不同語系使用者時再做。
- 設定頁、資料表、uninstall 流程：此外掛目前沒有持久化使用者設定；transient 本身可過期，無須為此增加 UI。
- rewrite rule 版本 option：目前 activation hook 已正確 flush；等規則真的在已安裝版本間改動時，再加入一次性的升級流程，不在每次 `init` 無條件 flush。
- 大型 HTML-to-Markdown 套件：先以幾個明確 helper 和測試補齊現有 converter；若轉換需求持續擴張，再評估成熟套件。

## 建議交付順序

1. 第一階段單獨做成安全修復版本。
2. 第二階段完成後，用小站與數百篇文章的資料各測一次查詢與快取。
3. 第三階段以測試案例驅動，避免一次重寫整個 converter。
4. 第四階段只挑實際會用到的項目，不阻擋安全修復發布。

## 最終驗證清單

- 三個 PHP 檔案通過 `php -l`。
- 啟用、停用與重新啟用沒有 fatal、warning 或重複 hook。
- `/llms.txt`、公開 `.md`、不存在 `.md`、密碼保護 `.md`、HEAD 請求皆驗證狀態碼與標頭。
- HEAD 請求設定與 GET 相同的必要標頭後直接結束，不計算 `Content-Length`，也不建立完整 body。
- Plain、文章名稱、巢狀頁面等固定網址模式均有預期結果。
- 快取命中與文章異動後的失效行為正確。
- transient 使用固定、外掛專屬的 key，不把 `home_url()` 或任何使用者輸入拼進 key。
- Markdown 特殊字元、表格、清單、圖片及程式碼案例人工比對輸出。
- 連續轉換兩次及模擬 `the_content` callback 丟出例外後，global post 與全部 callbacks 仍完整還原。
- 在沒有 ext-dom 的環境或模擬條件下不會 Fatal Error。
