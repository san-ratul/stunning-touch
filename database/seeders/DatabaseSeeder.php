<?php

namespace Database\Seeders;

use App\Models\AdPlacement;
use App\Models\AiSetting;
use App\Models\AutomationSetting;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\StaticPage;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->adminUser();
        $this->siteSettings();
        $this->categories();
        $this->tools();
        $this->adPlacements();
        $this->staticPages();
        $this->blog();
        $this->aiAndAutomation();
    }

    protected function adminUser(): void
    {
        if (User::query()->where('email', 'admin@stunningtouch.com')->doesntExist()) {
            User::create([
                'name' => 'Site Administrator',
                'email' => 'admin@stunningtouch.com',
                'password' => 'ChangeMe123!', // documented in README — change after first login
                'is_active' => true,
            ]);
        }
    }

    protected function siteSettings(): void
    {
        foreach ([
            'site_name' => 'Stunning Touch',
            'site_tagline' => 'Your Everyday Tools, All in One Place.',
            'site_description' => 'Stunning Touch offers free online tools for images, PDFs, text, developers and everyday tasks. Fast, private and browser-based.',
            'contact_email' => 'hello@stunningtouch.com',
            'footer_note' => 'All tools are free and processed in your browser.',
        ] as $key => $value) {
            SiteSetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    protected function categories(): void
    {
        $categories = [
            ['name' => 'Image Tools', 'slug' => 'image-tools', 'icon' => 'image', 'short_description' => 'Compress, resize and convert images directly in your browser.', 'description' => '<p>Optimize images for the web without uploading them anywhere. All image processing happens locally in your browser, so your photos stay private and conversions are instant.</p>'],
            ['name' => 'PDF Tools', 'slug' => 'pdf-tools', 'icon' => 'pdf', 'short_description' => 'Merge and split PDF files quickly and privately.', 'description' => '<p>Work with PDF documents without installing software. Our PDF tools process your files in the browser — documents are never uploaded to a server.</p>'],
            ['name' => 'Text Tools', 'slug' => 'text-tools', 'icon' => 'text', 'short_description' => 'Count words, convert case and clean up text in one click.', 'description' => '<p>Handy utilities for writers, students, marketers and anyone who works with text daily.</p>'],
            ['name' => 'Developer Tools', 'slug' => 'developer-tools', 'icon' => 'code', 'short_description' => 'Format JSON, encode Base64 and speed up everyday dev tasks.', 'description' => '<p>Lightweight utilities for developers: validate and format JSON, encode and decode Base64 safely with full Unicode support, and more.</p>'],
            ['name' => 'Generators', 'slug' => 'generators', 'icon' => 'sparkles', 'short_description' => 'Generate QR codes, UUIDs and other everyday values.', 'description' => '<p>Create QR codes and UUIDs instantly in your browser with full control over output options.</p>'],
        ];

        foreach ($categories as $i => $data) {
            $category = Category::firstOrCreate(
                ['slug' => $data['slug']],
                $data + ['display_order' => $i, 'is_active' => true]
            );

            $category->seo()->firstOrCreate([], [
                'meta_title' => "{$data['name']} — Free Online",
                'meta_description' => $data['short_description'],
                'focus_keyword' => Str::lower($data['name']),
                'robots_index' => true,
                'robots_follow' => true,
            ]);
        }
    }

    protected function tools(): void
    {
        $cat = fn (string $slug) => Category::where('slug', $slug)->value('id');

        $tools = [
            [
                'category' => 'image-tools', 'name' => 'Image Compressor', 'slug' => 'image-compressor',
                'icon' => 'image', 'featured' => true,
                'short_description' => 'Reduce image file size with adjustable quality — JPEG, PNG and WebP, processed in your browser.',
                'description' => '<p>The Image Compressor shrinks image file sizes while keeping good visual quality. Choose your output format (JPEG, WebP or PNG) and a quality level, and see the original vs compressed size with the percentage saved.</p><h2>Why compress images?</h2><p>Smaller images load faster, improve user experience and help Core Web Vitals scores. Compressing before uploading to your website can cut page weight significantly.</p><h2>Privacy first</h2><p>Your images are processed entirely in your browser using the Canvas API. Nothing is uploaded to any server.</p>',
                'how_to_title' => 'How to compress an image',
                'how_to_steps' => ['Drag and drop your image or click “Choose Image”.', 'Pick an output format — WebP usually gives the smallest files.', 'Adjust the quality slider to balance size and clarity.', 'Click “Download” to save the compressed image.'],
                'faq' => [
                    ['q' => 'Is my image uploaded to a server?', 'a' => 'No. Compression runs entirely in your browser; the file never leaves your device.'],
                    ['q' => 'Which format gives the best compression?', 'a' => 'WebP typically produces the smallest files at similar quality, followed by JPEG. PNG is lossless and stays larger.'],
                ],
                'seo' => [
                    'meta_title' => 'Free Image Compressor — Compress JPG, PNG & WebP Online',
                    'meta_description' => 'Compress images online for free. Reduce JPG, PNG and WebP file size with adjustable quality. 100% browser-based — your photos never leave your device.',
                    'focus_keyword' => 'image compressor',
                    'secondary_keywords' => 'compress jpg online, reduce image size, compress png, webp compressor',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'image-tools', 'name' => 'Image Resizer', 'slug' => 'image-resizer',
                'icon' => 'image', 'featured' => true,
                'short_description' => 'Resize images to exact pixel dimensions or percentages with aspect-ratio lock and presets.',
                'description' => '<p>Resize any image to exact dimensions — by pixels, percentage, or convenient presets for HD, Full HD, square social posts and vertical stories. Aspect-ratio lock keeps your pictures distortion-free.</p>',
                'how_to_title' => 'How to resize an image',
                'how_to_steps' => ['Upload your image.', 'Enter new width and height, pick a percentage, or choose a preset.', 'Select the output format.', 'Download your resized image.'],
                'faq' => [
                    ['q' => 'Will resizing reduce quality?', 'a' => 'Downscaling usually keeps images sharp. Enlarging beyond the original size can look soft — browsers interpolate pixels.'],
                ],
                'seo' => [
                    'meta_title' => 'Free Image Resizer — Resize JPG, PNG & WebP Online',
                    'meta_description' => 'Resize images online free. Set exact pixel dimensions, use percentage scaling or presets for social media. Aspect-ratio safe, no upload required.',
                    'focus_keyword' => 'image resizer',
                    'secondary_keywords' => 'resize image online, resize jpg, change image dimensions, photo resizer',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'image-tools', 'name' => 'JPG to PNG', 'slug' => 'jpg-to-png',
                'icon' => 'image',
                'short_description' => 'Convert JPG photos to PNG format instantly — right in your browser, no upload.',
                'description' => '<p>Convert JPEG images to PNG in one click. The conversion runs locally using the Canvas API, so your files stay on your device and the result is ready immediately.</p>',
                'how_to_title' => 'How to convert JPG to PNG',
                'how_to_steps' => ['Select or drop your JPG file.', 'The PNG is generated automatically.', 'Preview and click “Download”.'],
                'faq' => [
                    ['q' => 'Why convert JPG to PNG?', 'a' => 'PNG uses lossless compression, supports transparency and avoids repeated JPEG artefacts on further edits.'],
                ],
                'seo' => [
                    'meta_title' => 'JPG to PNG Converter — Free & Instant Online',
                    'meta_description' => 'Convert JPG to PNG online free. Instant, private, browser-based conversion with preview and download. No file upload required.',
                    'focus_keyword' => 'jpg to png',
                    'secondary_keywords' => 'jpeg to png converter, convert jpg to png free, online image converter',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'image-tools', 'name' => 'PNG to WebP', 'slug' => 'png-to-webp',
                'icon' => 'image',
                'short_description' => 'Convert PNG images to WebP with quality control for smaller, faster websites.',
                'description' => '<p>WebP usually produces files 25–35% smaller than PNG at similar visual quality. Convert your PNGs with an adjustable quality slider and see exactly how much you save.</p>',
                'how_to_title' => 'How to convert PNG to WebP',
                'how_to_steps' => ['Select or drop your PNG file.', 'Adjust the WebP quality slider.', 'Preview the result and its size.', 'Download your WebP image.'],
                'faq' => [
                    ['q' => 'Is WebP supported everywhere?', 'a' => 'All modern browsers (Chrome, Edge, Firefox, Safari 14+) support WebP. It is widely safe for websites today.'],
                ],
                'seo' => [
                    'meta_title' => 'PNG to WebP Converter — Free Online with Quality Control',
                    'meta_description' => 'Convert PNG to WebP online free with an adjustable quality slider. Smaller images for faster websites. Private, browser-based conversion.',
                    'focus_keyword' => 'png to webp',
                    'secondary_keywords' => 'convert png to webp, webp converter online, image to webp free',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'pdf-tools', 'name' => 'Merge PDF', 'slug' => 'merge-pdf',
                'icon' => 'pdf', 'featured' => true,
                'short_description' => 'Combine multiple PDF files into one — reorder pages files, merge and download, all locally.',
                'description' => '<p>Combine several PDF documents into a single file. Add as many PDFs as you need, drag-free reorder with move up/down controls, remove files, then merge. Processing uses pdf-lib in your browser — documents are never uploaded.</p>',
                'how_to_title' => 'How to merge PDF files',
                'how_to_steps' => ['Add two or more PDF files.', 'Reorder them until the sequence is right.', 'Click “Merge PDFs” and download the combined file.'],
                'faq' => [
                    ['q' => 'Are my PDFs uploaded anywhere?', 'a' => 'No. Merging happens entirely in your browser using pdf-lib; files never leave your device.'],
                    ['q' => 'Can I merge scanned PDFs?', 'a' => 'Yes — scanned PDFs merge like any other, though copy-protection may prevent processing.'],
                ],
                'seo' => [
                    'meta_title' => 'Merge PDF — Combine PDF Files Online Free',
                    'meta_description' => 'Merge PDF files online for free. Combine multiple PDFs into one document, reorder files and download instantly. Private, browser-based merging.',
                    'focus_keyword' => 'merge pdf',
                    'secondary_keywords' => 'combine pdf online, pdf joiner free, merge pdf files',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'pdf-tools', 'name' => 'Split PDF', 'slug' => 'split-pdf',
                'icon' => 'pdf',
                'short_description' => 'Extract pages from a PDF by range or custom selection with input validation.',
                'description' => '<p>Split a PDF by page range (e.g. pages 1–5) or extract custom pages (e.g. 1, 3, 5-8). The tool shows your document’s page count first and validates every input before processing.</p>',
                'how_to_title' => 'How to split a PDF',
                'how_to_steps' => ['Upload a PDF — its page count is detected automatically.', 'Choose a page range or enter custom pages.', 'Click “Extract Pages” and download the new PDF.'],
                'faq' => [
                    ['q' => 'How do I extract non-sequential pages?', 'a' => 'Choose “Custom pages” and list them like: 1, 3, 5-8. Ranges and singles can be mixed.'],
                ],
                'seo' => [
                    'meta_title' => 'Split PDF — Extract PDF Pages Online Free',
                    'meta_description' => 'Split PDF online free. Extract page ranges or custom pages (1, 3, 5-8) into a new PDF. Browser-based processing keeps files private.',
                    'focus_keyword' => 'split pdf',
                    'secondary_keywords' => 'extract pdf pages, pdf splitter online, separate pdf pages',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'text-tools', 'name' => 'Word Counter', 'slug' => 'word-counter',
                'icon' => 'text', 'featured' => true,
                'short_description' => 'Count words, characters, sentences, paragraphs and reading time as you type.',
                'description' => '<p>A real-time word counter for writers, students and SEO professionals. It tracks words, characters (with and without spaces), sentences, paragraphs, lines and estimated reading time — updating live as you type or paste.</p>',
                'how_to_title' => 'How to count words',
                'how_to_steps' => ['Type or paste your text into the editor.', 'Statistics update instantly below.', 'Use “Copy text” or “Clear” as needed.'],
                'faq' => [
                    ['q' => 'Does my text get stored?', 'a' => 'No. Everything happens in your browser; nothing is sent to or stored on a server.'],
                ],
                'seo' => [
                    'meta_title' => 'Word Counter — Count Words, Characters & Sentences Free',
                    'meta_description' => 'Free online word counter with live statistics: words, characters, sentences, paragraphs, lines and reading time. Nothing you type is uploaded.',
                    'focus_keyword' => 'word counter',
                    'secondary_keywords' => 'character counter, count words online, sentence counter, reading time calculator',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'text-tools', 'name' => 'Case Converter', 'slug' => 'case-converter',
                'icon' => 'text',
                'short_description' => 'Convert text between UPPERCASE, lowercase, Title Case, Sentence case and more.',
                'description' => '<p>Six conversion modes: UPPERCASE, lowercase, Title Case, Sentence case, Capitalized Words and iNVERSE cASE. One click converts your text; copy the result instantly.</p>',
                'how_to_title' => 'How to convert text case',
                'how_to_steps' => ['Paste your text.', 'Click the conversion style you need.', 'Copy the converted result.'],
                'faq' => [
                    ['q' => 'What is Title Case?', 'a' => 'Title Case capitalizes the first letter of each word — commonly used for headings and titles.'],
                ],
                'seo' => [
                    'meta_title' => 'Case Converter — UPPERCASE, lowercase, Title Case Online',
                    'meta_description' => 'Convert text case online free: uppercase, lowercase, Title Case, Sentence case, Capitalized Words and Inverse Case. Instant and private.',
                    'focus_keyword' => 'case converter',
                    'secondary_keywords' => 'uppercase to lowercase, title case converter, sentence case online',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'developer-tools', 'name' => 'JSON Formatter', 'slug' => 'json-formatter',
                'icon' => 'code', 'featured' => true,
                'short_description' => 'Format, minify and validate JSON with clear error messages — perfect for debugging.',
                'description' => '<p>Paste JSON to validate it, pretty-print it with 2-space indentation, or minify it for production. Errors are reported with precise, helpful messages, and basic structure stats show key counts and nesting depth.</p>',
                'how_to_title' => 'How to format JSON',
                'how_to_steps' => ['Paste your JSON into the input panel.', 'Click “Format” to pretty-print or “Minify” to compact.', 'Invalid JSON shows the exact error; valid JSON is ready to copy.'],
                'faq' => [
                    ['q' => 'Is it safe to paste API responses here?', 'a' => 'Yes — parsing happens entirely in your browser. Nothing is transmitted or stored.'],
                ],
                'seo' => [
                    'meta_title' => 'JSON Formatter & Validator — Format, Minify JSON Online',
                    'meta_description' => 'Format JSON online free with syntax validation and clear error messages. Minify or pretty-print JSON instantly in your browser.',
                    'focus_keyword' => 'json formatter',
                    'secondary_keywords' => 'json validator online, json beautifier, minify json, json pretty print',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'developer-tools', 'name' => 'Base64 Encoder/Decoder', 'slug' => 'base64-encoder-decoder',
                'icon' => 'code',
                'short_description' => 'Encode and decode Base64 with full Unicode safety and URL-safe output option.',
                'description' => '<p>Convert text to Base64 and back with proper UTF-8 handling — emojis, Bangla, Chinese and any non-Latin script encode correctly. Optional URL-safe output replaces +/ with -_ for use in URLs and tokens.</p>',
                'how_to_title' => 'How to encode and decode Base64',
                'how_to_steps' => ['Paste your text (to encode) or Base64 string (to decode).', 'Click “Encode” or “Decode”.', 'Copy the result.'],
                'faq' => [
                    ['q' => 'What does URL-safe Base64 mean?', 'a' => 'It replaces + and / with - and _ and drops padding, so the value can be safely embedded in URLs and tokens.'],
                ],
                'seo' => [
                    'meta_title' => 'Base64 Encoder & Decoder — Unicode-Safe, Free Online',
                    'meta_description' => 'Encode and decode Base64 online free. Full Unicode (UTF-8) support, URL-safe output option, instant results — processed entirely in your browser.',
                    'focus_keyword' => 'base64 encoder decoder',
                    'secondary_keywords' => 'base64 encode online, base64 decode, base64 converter',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'generators', 'name' => 'QR Code Generator', 'slug' => 'qr-code-generator',
                'icon' => 'sparkles', 'featured' => true,
                'short_description' => 'Create QR codes for URLs and text with size, colors and error-correction control. PNG & SVG download.',
                'description' => '<p>Generate crisp QR codes for links, Wi-Fi strings, contact info or any text. Control the size, quiet zone, foreground/background colors and error-correction level. Download as PNG or vector SVG for print.</p>',
                'how_to_title' => 'How to create a QR code',
                'how_to_steps' => ['Type your text or URL.', 'Tune size, colors and error correction if needed.', 'Download as PNG or SVG.'],
                'faq' => [
                    ['q' => 'Do the QR codes expire?', 'a' => 'Never. The data is encoded directly in the image — there is no redirect service involved.'],
                ],
                'seo' => [
                    'meta_title' => 'QR Code Generator — Free PNG & SVG QR Codes Online',
                    'meta_description' => 'Generate QR codes online free. Custom size, colors and error correction; download as PNG or SVG. No sign-up, no expiry, made in your browser.',
                    'focus_keyword' => 'qr code generator',
                    'secondary_keywords' => 'free qr code maker, create qr code for url, qr code svg',
                    'schema_type' => 'WebApplication',
                ],
            ],
            [
                'category' => 'generators', 'name' => 'UUID Generator', 'slug' => 'uuid-generator',
                'icon' => 'code',
                'short_description' => 'Generate secure UUID v4 identifiers in bulk with copy-one and copy-all options.',
                'description' => '<p>Create up to 100 UUID v4 values at once using the browser’s cryptographic random generator. Options for uppercase and dash-less output; copy individual values or everything with one click.</p>',
                'how_to_title' => 'How to generate UUIDs',
                'how_to_steps' => ['Choose how many UUIDs you need.', 'Click “Generate UUIDs”.', 'Copy a single value or all of them.'],
                'faq' => [
                    ['q' => 'What is UUID v4?', 'a' => 'A 128-bit identifier randomly generated with 122 random bits — the most common UUID version for database keys and distributed systems.'],
                ],
                'seo' => [
                    'meta_title' => 'UUID Generator — Free Online UUID v4 Generator (Bulk)',
                    'meta_description' => 'Generate UUID v4 identifiers online free in bulk (up to 100). Cryptographically random, with uppercase and no-dash options and one-click copy.',
                    'focus_keyword' => 'uuid generator',
                    'secondary_keywords' => 'uuid v4 generator online, guid generator, random uuid',
                    'schema_type' => 'WebApplication',
                ],
            ],
        ];

        foreach ($tools as $i => $data) {
            $categorySlug = $data['category'];
            unset($data['category'], $data['seo']);

            $tool = Tool::firstOrCreate(
                ['slug' => $data['slug']],
                [
                    'category_id' => $cat($categorySlug),
                    'name' => $data['name'],
                    'short_description' => $data['short_description'],
                    'description' => $data['description'],
                    'icon' => $data['icon'],
                    'component_key' => $data['slug'],
                    'is_active' => true,
                    'is_featured' => (bool) ($data['featured'] ?? false),
                    'display_order' => $i,
                    'how_to_title' => $data['how_to_title'] ?? null,
                    'how_to_steps' => $data['how_to_steps'] ?? null,
                    'faq' => $data['faq'] ?? null,
                ]
            );

            $seo = $data['seo'] ?? [];
            $tool->seo()->firstOrCreate([], $seo + [
                'robots_index' => true,
                'robots_follow' => true,
            ]);
        }
    }

    protected function adPlacements(): void
    {
        foreach ([
            ['homepage_top', 'Homepage Top', 'Above the hero section on the homepage.'],
            ['homepage_content', 'Homepage Content', 'Between homepage sections.'],
            ['homepage_bottom', 'Homepage Bottom', 'Below homepage content.'],
            ['category_top', 'Category Top', 'Above the tools grid on category pages.'],
            ['tool_before_content', 'Tool Before Content', 'Above the tool interface.'],
            ['tool_after_content', 'Tool After Content', 'Below the tool interface.'],
            ['tool_sidebar', 'Tool Sidebar', 'Tool page sidebar (desktop).'],
            ['tool_bottom', 'Tool Bottom', 'Below tool content and FAQ.'],
            ['blog_content', 'Blog Content', 'Blog listing pages.'],
            ['blog_sidebar', 'Blog Sidebar', 'Blog sidebar (desktop).'],
            ['blog_footer', 'Blog Footer', 'Below article content.'],
        ] as $placement) {
            AdPlacement::firstOrCreate(
                ['key' => $placement[0]],
                ['name' => $placement[1], 'description' => $placement[2], 'is_active' => true]
            );
        }
    }

    protected function staticPages(): void
    {
        $pages = [
            [
                'title' => 'About Stunning Touch',
                'slug' => 'about',
                'content' => <<<'HTML'
<p><strong>Stunning Touch</strong> is a free all-in-one online tools platform. Our mission is simple: give everyone fast, reliable, everyday tools in one place — with your privacy respected by design.</p>

<h2>What we offer</h2>
<ul>
    <li><strong>Image tools</strong> — compress, resize and convert images without uploading them anywhere.</li>
    <li><strong>PDF tools</strong> — merge and split documents entirely in your browser.</li>
    <li><strong>Text tools</strong> — word counting, case conversion and everyday text cleanup.</li>
    <li><strong>Developer tools</strong> — JSON formatting, Base64 encoding and decoding.</li>
    <li><strong>Generators</strong> — QR codes and UUIDs on demand.</li>
</ul>

<h2>Our privacy promise</h2>
<p>File-processing tools run 100% in your browser. Your images, PDFs and text are never uploaded to our servers. Analytics on our site are privacy-conscious: we never store raw IP addresses or the content you process.</p>

<h2>How we stay free</h2>
<p>The platform is supported by advertising. Ads are clearly separated from tool content and never interfere with functionality.</p>

<h2>Contact</h2>
<p>Questions, feedback or partnership ideas? Use our <a href="/contact">contact page</a> — we read everything.</p>
HTML,
            ],
            [
                'title' => 'Privacy Policy',
                'slug' => 'privacy-policy',
                'content' => <<<'HTML'
<p>This Privacy Policy explains how Stunning Touch ("we", "our", "us") handles information when you visit stunningtouch.com. We designed this site to collect as little personal data as possible.</p>

<h2>1. Information we do NOT collect</h2>
<ul>
    <li>We do not collect the files you process (images, PDFs) — these tools run entirely in your browser.</li>
    <li>We do not collect text you type into tools such as the Word Counter or JSON Formatter.</li>
    <li>We do not store raw IP addresses.</li>
    <li>We do not require accounts for public tools.</li>
</ul>

<h2>2. Information we collect</h2>
<ul>
    <li><strong>Anonymous usage analytics:</strong> page views, referrer domain, approximate country (when provided by our network edge), device type and browser. Visitors are identified by a rotating daily hash — not by IP address or persistent identifiers.</li>
    <li><strong>Contact form:</strong> if you contact us, we receive the name, email and message you provide, used solely to reply.</li>
    <li><strong>Admin accounts:</strong> authorized staff accounts store credentials securely (hashed passwords). Public visitors never create accounts.</li>
</ul>

<h2>3. Advertising</h2>
<p>We display advertising to keep tools free. Ad networks may use cookies or device identifiers subject to their own privacy policies. Where required by law, we present consent choices before personalized advertising. Ads never receive the content of the files or text you process.</p>

<h2>4. Cookies</h2>
<p>We use only the cookies required for core functionality (session cookie for administrators, security tokens). Advertising partners may set additional cookies per their policies and applicable consent rules.</p>

<h2>5. Data retention</h2>
<p>Anonymous analytics records are retained for a limited period (typically 90 days) and then deleted. Contact messages are kept only as long as needed to handle your inquiry.</p>

<h2>6. Your rights</h2>
<p>Depending on your jurisdiction (including GDPR and similar laws), you may request access to, correction of, or deletion of personal data we hold about you. Because we collect so little data, most requests can be satisfied by simply using the site anonymously.</p>

<h2>7. Changes</h2>
<p>We may update this policy as the platform evolves. The "last updated" date below reflects the current version.</p>

<p><em>Last updated: November 2026</em></p>
HTML,
            ],
            [
                'title' => 'Terms of Service',
                'slug' => 'terms',
                'content' => <<<'HTML'
<p>By using Stunning Touch you agree to these terms. Please read them carefully.</p>

<h2>1. Use of the service</h2>
<ul>
    <li>Our tools are provided free of charge for lawful personal and business use.</li>
    <li>You may not use the platform to process unlawful content or to attack, overload or reverse-engineer the service.</li>
    <li>Automated scraping or abusive traffic may be rate-limited or blocked.</li>
</ul>

<h2>2. No warranty</h2>
<p>Tools are provided "as is" without warranties of any kind. Always keep backups of important files — while our tools run in your browser and do not alter originals, you remain responsible for your data.</p>

<h2>3. Limitation of liability</h2>
<p>To the maximum extent permitted by law, Stunning Touch is not liable for indirect or consequential damages arising from use of the platform, including data loss or business interruption.</p>

<h2>4. Advertising</h2>
<p>The platform displays third-party advertising. We do not control ad content and are not responsible for products or services advertised.</p>

<h2>5. Intellectual property</h2>
<p>The site design, brand and code are the property of Stunning Touch. You retain all rights to the content you process with our tools.</p>

<h2>6. Changes to the service</h2>
<p>We may add, change or discontinue tools at any time. These terms may be updated; continued use constitutes acceptance.</p>

<p><em>Last updated: November 2026</em></p>
HTML,
            ],
            [
                'title' => 'Disclaimer',
                'slug' => 'disclaimer',
                'content' => <<<'HTML'
<p>The information and tools on Stunning Touch are provided for general informational and utility purposes.</p>

<h2>Tool accuracy</h2>
<p>We work hard to make every tool accurate and reliable, but results (sizes, counts, conversions, generated identifiers) should be verified for critical use cases. We make no guarantees of fitness for a particular purpose.</p>

<h2>No professional advice</h2>
<p>Blog articles and guides are educational content, not professional, legal, financial or medical advice.</p>

<h2>External links and ads</h2>
<p>Links to third-party websites and advertisements are provided for convenience. We do not endorse and are not responsible for their content, products or services.</p>

<h2>AI-assisted content</h2>
<p>Some blog articles may be prepared with AI assistance and reviewed by our editors before publishing. Such content is clearly produced to our quality standards; where research claims appear, they are based on publicly available information rather than paid data providers.</p>

<p><em>Last updated: November 2026</em></p>
HTML,
            ],
        ];

        foreach ($pages as $data) {
            $page = StaticPage::firstOrCreate(['slug' => $data['slug']], $data + ['is_active' => true]);
            $page->seo()->firstOrCreate([], [
                'meta_title' => $data['title'],
                'meta_description' => Str::limit(strip_tags($data['content']), 150),
                'robots_index' => true,
                'robots_follow' => true,
            ]);
        }
    }

    protected function blog(): void
    {
        $categories = [
            ['name' => 'Image Optimization', 'slug' => 'image-optimization', 'description' => 'Guides on compressing, resizing and converting images for a faster web.'],
            ['name' => 'PDF Tips', 'slug' => 'pdf-tips', 'description' => 'Working smarter with PDF documents.'],
            ['name' => 'Productivity', 'slug' => 'productivity', 'description' => 'Tools, workflows and habits that save time every day.'],
        ];

        foreach ($categories as $data) {
            BlogCategory::firstOrCreate(['slug' => $data['slug']], $data + ['is_active' => true]);
        }

        $admin = User::query()->first();

        $posts = [
            [
                'title' => 'How to Compress Images for the Web Without Losing Quality',
                'slug' => 'how-to-compress-images-for-web',
                'blog_category' => 'image-optimization',
                'excerpt' => 'A practical guide to shrinking image file sizes: choosing formats, picking quality levels and hitting fast load times.',
                'content' => <<<'HTML'
<p>Large images are the most common reason websites feel slow. Compressing them properly can cut page weight by more than half without visible quality loss.</p>

<h2>Choose the right format</h2>
<ul>
    <li><strong>JPEG</strong> — best for photographs. Good compression, no transparency.</li>
    <li><strong>PNG</strong> — best for screenshots, logos and images needing transparency, but files stay large.</li>
    <li><strong>WebP</strong> — usually 25–35% smaller than JPEG at similar quality, with transparency support. Supported by all modern browsers.</li>
</ul>

<h2>Pick a sensible quality level</h2>
<p>For photos, quality around 70–85% is usually indistinguishable from the original in everyday viewing. Below 60% artefacts start to appear. Compress, preview, compare — then decide.</p>

<h2>Resize before compressing</h2>
<p>Do not ship a 4000px-wide photo for a 800px content column. Resizing to the actual display size first makes compression far more effective.</p>

<h2>Compress right in your browser</h2>
<p>You can do all of this without uploading files anywhere — try the <a href="https://stunningtouch.com/image-compressor">Image Compressor</a> and the <a href="https://stunningtouch.com/image-resizer">Image Resizer</a>. Both run locally in your browser, so your images stay on your device.</p>

<h2>Quick checklist</h2>
<ul>
    <li>Resize to the real display size</li>
    <li>Prefer WebP for photos</li>
    <li>Keep quality around 75–85%</li>
    <li>Use <code>loading="lazy"</code> on below-the-fold images</li>
</ul>
HTML,
            ],
            [
                'title' => 'Merging PDFs the Safe Way: 4 Tips for Clean Combined Documents',
                'slug' => 'merging-pdfs-the-safe-way',
                'blog_category' => 'pdf-tips',
                'excerpt' => 'Order files correctly, watch page orientation and protect privacy — four practical tips for merging PDF documents.',
                'content' => <<<'HTML'
<p>Combining PDFs sounds trivial, but a little care produces much cleaner results — especially for reports, applications and invoices.</p>

<h2>1. Get the order right first</h2>
<p>Before merging, arrange your files in the final order you want. Renaming files with numeric prefixes (01-intro.pdf, 02-report.pdf…) keeps everything predictable.</p>

<h2>2. Mind mixed page sizes</h2>
<p>Merging an A4 report with a US Letter scan produces a document with mixed page sizes. Most viewers handle it fine, but for print it is worth normalizing first.</p>

<h2>3. Keep sensitive files local</h2>
<p>Contracts, IDs and financial documents should never be uploaded to unknown servers. Browser-based tools like <a href="https://stunningtouch.com/merge-pdf">Merge PDF</a> and <a href="https://stunningtouch.com/split-pdf">Split PDF</a> process files entirely on your device — nothing is transmitted anywhere.</p>

<h2>4. Split out what you need</h2>
<p>After merging, you may want to share just a section. Extracting specific pages into a separate file keeps what you share minimal — good practice for both privacy and clarity.</p>
HTML,
            ],
        ];

        foreach ($posts as $data) {
            $categorySlug = $data['blog_category'];
            unset($data['blog_category']);

            $post = BlogPost::firstOrCreate(
                ['slug' => $data['slug']],
                $data + [
                    'user_id' => $admin?->id,
                    'blog_category_id' => BlogCategory::where('slug', $categorySlug)->value('id'),
                    'status' => 'published',
                    'source' => 'manual',
                    'published_at' => now()->subDays(2),
                    'reading_time' => BlogPost::readingTimeFor($data['content']),
                    'meta_description' => Str::limit(strip_tags($data['excerpt']), 150),
                ]
            );
        }
    }

    protected function aiAndAutomation(): void
    {
        AiSetting::firstOrCreate([], [
            'provider' => 'openai-compatible',
            'is_active' => false,
        ]);

        AutomationSetting::firstOrCreate([], [
            'is_enabled' => false,
            'frequency' => 'daily',
            'preferred_time' => '09:00',
            'timezone' => 'Asia/Dhaka',
        ]);
    }
}
