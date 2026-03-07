# MoeLog LLMs.txt

**為 AI 語言模型打造的高效、純淨 WordPress 內容索引外掛**

這是一款專為生成式 AI（如 ChatGPT、Claude）、AI 搜尋引擎（如 Perplexity）以及其他大型語言模型 (LLM) 設計的 WordPress 外掛。它完美實作了新興的 [`llms.txt` 規範](https://llmstxt.org/)，為機器人提供友善的網站目錄與純淨的 Markdown 內容，讓你的網站內容能更精準、無干擾地被 AI 讀取與理解。

## ✨ 核心特色

- **遵循 `llms.txt` 標準**：自動生成網站索引檔案 `/llms.txt`，清楚列出所有已發布的「文章」與「頁面」。
- **Markdown 純文字輸出**：在任何文章或頁面的網址最後加上 `.md` (例如 `https://example.com/about.md`)，即可取得該網頁的純 Markdown 版本。
- **深度 HTML 淨化引擎**：
  - 自動剝除 `header`、`footer`、`nav`、`aside`、`script`、`style` 等與內容無關的網頁元素。
  - 將核心圖文內容俐落地轉換為符合標準的 Markdown。
- **智慧噪音過濾 (Noise Filter)**：
  - 動態攔截並**暫時移除**來自 Jetpack、YARPP 或廣告外掛等會自動注入相關文章、社群按鈕的干擾源。
  - 確保餵給 AI 的內容只有最精華的文章本體，不浪費 AI 的 Context Window。
- **完美的 SEO 隔離機制**：
  - 自動在生成的 `.md` 頁面加上 `X-Robots-Tag: noindex`，防止傳統搜尋引擎（如 Google）將其視為重複內容 (Duplicate Content) 而影響原本網站的 SEO 排名。
  - 移除傳統 `robots.txt` 封鎖，確保如 `GPTBot` 或 `ClaudeBot` 能夠順利存取你的 `.md` 檔案。
- **邊緣快取友善 (Edge Cache Friendly)**：
  - 加註 `Cache-Control: public, max-age=3600`，完美相容 Cloudflare 等 CDN，大幅降低高頻繁 AI 爬蟲對主機資料庫造成的負擔。

## 🚀 安裝與使用

1. 將 `moelog-llms-txt` 資料夾上傳至網站的 `/wp-content/plugins/` 目錄，或透過 WordPress 後台的外掛安裝介面上傳 ZIP 檔。
2. 在 WordPress 後台啟用 **MoeLog LLMs.txt** 外掛。
3. 啟用後，建議前往 WordPress 後台的「**設定 > 固定網址 (Permalinks)**」，直接點擊一次「**儲存變更**」，以確保新的 Rewrite Rules 能夠順利生效。

### 測試功能

- 存取 `https://你的網址.com/llms.txt` 檢查索引是否正確生成。
- 挑選任一篇文章網址，在結尾加上 `.md`（例：`https://你的網址.com/hello-world.md`），檢視那極度乾淨且賞心悅目的 Markdown 原始碼！

## 💡 開發初衷與架構精神

隨著 AI 應用的爆發，未來的流量入口不再僅限於傳統的 Google 搜尋，而是各式各樣的 AI Agent 與智能助理。傳統帶有極多 DOM 結構、複雜 CSS 與廣告追蹤碼的 HTML 網頁，對於這些 AI 來說是充滿干擾且難以消化的。

本外掛的誕生，就是要為你的 WordPress 網站開闢一條**專屬且優雅的「AI 快速通道」**：
不需要重新造輪子，不用提供肥大且消耗資源的 `llms-full.txt`，而是讓 AI 先透過精簡的 `/llms.txt` 了解網站輪廓，再根據其運算需求，精準且低負載地抓取特定的 `/*.md` 純文字內容。

## 🧑‍💻 自訂與擴充

如果你安裝了其他會對文章注入雜訊的外掛（例如特定的廣告插入工具），你可以透過修改 `includes/class-llms-txt.php` 檔案中的 `$noise_patterns` 陣列，加入這些外掛常使用的函數或物件名稱關鍵字，`MoeLog LLMs.txt` 就能自動在產出 Markdown 時幫你把它們過濾掉。

```php
// 常見注入廣告、相關文章、社群按鈕的 callback 關鍵字
$noise_patterns = array(
    'inject_ads',
    'related_posts',
    'share_buttons',
    'social_share',
    'yarpp',
    'wpp_',
    'jetpack',
    // 'my_custom_ads_plugin', // 在這裡新增你的雜訊外掛關鍵字
);
```

## 📄 授權條款

GPL-2.0+
