# Blog post

[`blog-post.html`](blog-post.html) is a ready-to-publish WordPress blog post that introduces the project. It's about 1,000 words, a 5-minute read. It uses plain HTML, so it works in the block editor, Divi and the classic editor.

## Suggested post details

| Field | Suggestion |
|---|---|
| **Title** | How I built a 24/7 broadband monitor for my YouFibre connection (and you can too) |
| **Excerpt** | A £5 ESP32 and a free WordPress plugin that watch my broadband 24/7 and record exactly when it goes down and comes back up, with email alerts and a downloadable report. |
| **Tags** | broadband, YouFibre, ESP32, WordPress, home network, uptime monitor, Arduino |

## How to paste it into WordPress

1. Open `blog-post.html` in Notepad or any text editor, press **Ctrl+A**, then **Ctrl+C**.
2. In WordPress, go to **Posts → Add New Post** and enter the title.
3. Paste the body using whichever editor you use:
   * **Block editor:** click **⋮** (top right), then **Code editor**, paste, then click **Exit code editor**. Or add a **Custom HTML** block and paste into it.
   * **Divi Builder:** add a **Text** module, open its **Text** tab (not Visual), and paste.
   * **Classic editor:** open the **Text** tab (not Visual) and paste.
4. Click **Preview**, then **Publish**.

## Optional extras

* **Live uptime report:** turn on **Broadband Uptime → Settings → Public report**, then add the shortcode `[netmon_report days="30"]` anywhere in the post.
* **Featured image:** a screenshot of the green "Receiving heartbeats from the ESP32" panel works well.
* **Your own results:** once you have a month of data, add a line such as "in September it dropped out 3 times for a total of 12 minutes".
