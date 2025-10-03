<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Schema;
use App\Models\Product;

class ChatBotController extends Controller
{
    /** Trang giao diện chat */
    public function index(Request $request)
    {
        return view('chat.ai');
    }

    /** Lịch sử (widget mini) */
    public function messages(Request $request)
    {
        $history = $request->session()->get('chat_ai_history', []);
        $out = [];
        foreach ($history as $h) {
            $out[] = [
                'me'   => (($h['role'] ?? 'user') === 'user'),
                'body' => (string)($h['content'] ?? ''),
                'time' => '',
            ];
        }
        return response()->json(['messages' => $out]);
    }

    /** Unread badge (AI-only) */
    public function unread()
    {
        return response()->json(['unread' => 0]);
    }

    /**
     * CHỈ GEMINI
     * - Phân loại intent: nếu là mua/giá/size… => chèn RAG sản phẩm (KHÔNG link) + policy
     * - Gọi Gemini generateContent (non-stream), bọc SSE để FE đọc "output_text"
     */
    public function stream(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:20000',
            'history' => 'array',
            'history.*.role'    => 'in:user,assistant,system',
            'history.*.content' => 'string',
        ]);

        if (!extension_loaded('curl')) {
            return response()->json(['error' => 'PHP cURL extension is not enabled'], 500);
        }

        // ENV cho Gemini
        $apiKey = (string) env('GEMINI_API_KEY', '');
        $model  = (string) env('GEMINI_MODEL', 'gemini-1.5-pro');
        $apiVer = (string) env('GEMINI_API_VER', 'v1beta'); // đổi v1/v1beta nếu cần
        if ($apiKey === '') {
            return response()->json(['error' => 'GEMINI_API_KEY is missing'], 500);
        }

        // Lịch sử
        $userMsg        = (string) $data['message'];
        $sessionHistory = $request->session()->get('chat_ai_history', []);
        $extraHistory   = array_values(array_filter(($data['history'] ?? []), fn($m) =>
            isset($m['role'],$m['content']) && is_string($m['content']) && $m['content']!==''
        ));
        $mergedHistory  = array_slice($sessionHistory, max(0, count($sessionHistory) - 16));

        // Intent
        $isProduct = $this->isProductIntent($userMsg);

        // System + RAG (KHÔNG link; chỉ tên/giá/ảnh)
        if ($isProduct) {
            [$catalogText, $policyText] = $this->buildShopContextNoLink($userMsg);
            $systemContent =
                "Bạn là trợ lý bán hàng của SHOPEDDIE. Chỉ trả lời dựa trên dữ liệu & chính sách sau.\n".
                "=== SẢN PHẨM PHÙ HỢP ===\n{$catalogText}\n=== HẾT ===\n".
                "=== CHÍNH SÁCH ===\n{$policyText}\n=== HẾT ===\n".
                "Khi giới thiệu, chỉ nêu (1) tên, (2) giá và có thể gợi ý size/màu. KHÔNG chèn link, KHÔNG dùng Markdown link. ".
                "Nếu có ảnh minh hoạ, có thể giữ định dạng mỗi dòng: '• Tên — Giá — IMG_URL' (dùng dấu gạch dài —). ".
                "Nếu câu hỏi ngoài dữ liệu, hãy xin lỗi và gợi ý xem danh mục.";
        } else {
            $systemContent =
                "Bạn là trợ lý AI tiếng Việt của SHOPEDDIE. Trả lời thân thiện, ngắn gọn, dễ hiểu. ".
                "Nếu người dùng chuyển sang hỏi về sản phẩm/giá/size… hãy hỏi thêm nhu cầu hoặc gợi ý mở danh mục. ".
                "Không bịa thông tin.";
        }

        // Chuyển history -> Gemini contents
        // role: 'assistant' -> 'model', còn lại -> 'user'
        $geminiContents = [];
        $push = function (string $role, string $text) use (&$geminiContents) {
            if ($text === '') return;
            $gemRole = ($role === 'assistant') ? 'model' : 'user';
            $geminiContents[] = ['role' => $gemRole, 'parts' => [['text' => $text]]];
        };
        $push('system', $systemContent);
        foreach ($mergedHistory as $m) $push((string)$m['role'], (string)$m['content']);
        foreach ($extraHistory as $m)  $push((string)$m['role'], (string)$m['content']);
        $push('user', $userMsg);

        $assistantText = '';

        // SSE: gọi Gemini non-stream, bọc output
        $response = new StreamedResponse(function () use (&$assistantText, $apiKey, $apiVer, $model, $geminiContents) {
            // Chuẩn SSE
            ignore_user_abort(true);
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            while (ob_get_level() > 0) { @ob_end_flush(); }
            @ob_implicit_flush(1);

            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            header('Connection: keep-alive');

            // Heartbeat sớm
            echo "event: ping\n";
            echo "data: \"start\"\n\n";
            @flush();

            // Gọi 1 lần theo ENV
            [$text, $err] = $this->geminiGenerateTextFromContents($geminiContents, $apiKey, $apiVer, $model);

            if ($err) {
                echo "event: error\n";
                echo "data: " . json_encode(['error' => $err], JSON_UNESCAPED_UNICODE) . "\n\n";
                echo "event: done\n"; echo "data: [DONE]\n\n"; @flush(); return;
            }

            $assistantText = (string)$text;

            // Gửi theo format FE đang parse
            echo "data: " . json_encode(['output_text' => $assistantText], JSON_UNESCAPED_UNICODE) . "\n\n";
            echo "event: done\n";
            echo "data: [DONE]\n\n";
            @flush();
        });

        // Lưu lịch sử
        app()->terminating(function () use ($request, $userMsg, &$assistantText) {
            $h = $request->session()->get('chat_ai_history', []);
            if ($userMsg !== '')             $h[] = ['role' => 'user',      'content' => $userMsg];
            if (trim($assistantText) !== '') $h[] = ['role' => 'assistant', 'content' => $assistantText];
            $request->session()->put('chat_ai_history', $h);
        });

        $response->headers->set('Cache-Control', 'no-cache');
        return $response;
    }

    // ===================== HELPERS =====================

    /** Intent sản phẩm/mua hàng */
    private function isProductIntent(string $text): bool
    {
        $t = mb_strtolower($text, 'UTF-8');
        $keywords = [
            'mua','đặt','giá','bao nhiêu','size','kích cỡ','màu','có hàng','tồn kho',
            'ship','vận chuyển','đổi trả','bảo hành',
            'áo','quần','váy','giày','sandal','áo thun','áo phông','áo sơ mi','hoodie',
            'sản phẩm','product','đặt hàng','checkout','cart','giỏ hàng'
        ];
        foreach ($keywords as $k) {
            if (mb_strpos($t, $k, 0, 'UTF-8') !== false) return true;
        }
        return false;
    }

    /**
     * RAG KHÔNG LINK: tạo danh sách sản phẩm dạng "• Tên — Giá — IMG_URL"
     * Ưu tiên cột ảnh có sẵn (image, thumbnail, thumb, cover, img_url, photo)
     */
    private function buildShopContextNoLink(string $userMsg): array
    {
        $intent = mb_strtolower($userMsg, 'UTF-8');
        $kw = array_values(array_filter(preg_split('/\s+/u', $intent), fn($w) => mb_strlen($w,'UTF-8') >= 2));

        $products = $this->findProducts($kw, 12);
        if ($products->isEmpty()) {
            $products = Product::query()
                ->when($this->safeHasColumn('products','sold'), fn($q)=>$q->orderByDesc('sold'))
                ->when(!$this->safeHasColumn('products','sold'), fn($q)=>$q->latest('id'))
                ->select($this->selectableColumnsWithImage())
                ->take(12)->get();
        }

        $nameCol  = $this->firstExistingColumn('products', ['name','title']);
        $priceCol = $this->firstExistingColumn('products', ['price','sale_price','final_price']);
        $imgCol   = $this->firstExistingColumn('products', ['image','thumbnail','thumb','cover','img_url','photo','picture']);

        $lines = [];
        foreach ($products as $p) {
            $name  = $nameCol  ? (string)($p->{$nameCol}  ?? ('SP#'.$p->id)) : ('SP#'.$p->id);
            $price = $this->formatPrice($priceCol ? ($p->{$priceCol} ?? null) : null);
            $img   = $this->imageUrl($p, $imgCol); // có thể rỗng
            // KHÔNG chèn link sản phẩm. Chỉ để IMG_URL (nếu có)
            $lines[] = $img
                ? "• {$name} — {$price} — {$img}"
                : "• {$name} — {$price}";
        }
        $catalogText = $lines ? implode("\n", $lines) : "Không có sản phẩm phù hợp.";

        $policyText =
            "Giao hàng: 2–5 ngày toàn quốc; phí tuỳ khu vực.\n".
            "Thanh toán: COD, VietQR, VNPay.\n".
            "Đổi trả: 7 ngày nếu lỗi NSX, còn tem/mác, chưa sử dụng.\n".
            "Hỗ trợ size/màu: gợi ý 2–4 lựa chọn phù hợp nhất.";

        return [$catalogText, $policyText];
    }

    /** Tìm sản phẩm theo từ khoá, ưu tiên còn hàng/bán chạy */
    private function findProducts(array $keywords, int $limit = 12)
    {
        $table   = 'products';
        $nameCol = $this->firstExistingColumn($table, ['name','title']);
        $slugCol = $this->safeHasColumn($table, 'slug') ? 'slug' : null;

        return Product::query()
            ->when(!empty($keywords) && $nameCol, function ($q) use ($keywords, $nameCol, $slugCol) {
                $q->where(function ($qq) use ($keywords, $nameCol, $slugCol) {
                    foreach ($keywords as $w) {
                        $qq->orWhere($nameCol, 'like', "%{$w}%");
                        if ($slugCol) $qq->orWhere($slugCol, 'like', "%{$w}%");
                    }
                });
            })
            ->when($this->safeHasColumn($table, 'status'), fn($q) => $q->where('status', 1))
            ->when($this->safeHasColumn($table, 'stock'),  fn($q) => $q->where('stock', '>', 0))
            ->when($this->safeHasColumn($table, 'sold'),   fn($q) => $q->orderByDesc('sold'))
            ->select($this->selectableColumnsWithImage())
            ->take($limit)
            ->get();
    }

    /** Các cột cần select (thêm ảnh) */
    private function selectableColumnsWithImage(): array
    {
        $table    = 'products';
        $nameCol  = $this->firstExistingColumn($table, ['name','title']);
        $priceCol = $this->firstExistingColumn($table, ['price','sale_price','final_price']);
        $imgCol   = $this->firstExistingColumn($table, ['image','thumbnail','thumb','cover','img_url','photo','picture']);

        return array_values(array_filter([
            'id',
            $nameCol,
            $priceCol,
            $imgCol,
            $this->safeHasColumn($table, 'slug')   ? 'slug'   : null,
            $this->safeHasColumn($table, 'sold')   ? 'sold'   : null,
            $this->safeHasColumn($table, 'stock')  ? 'stock'  : null,
            $this->safeHasColumn($table, 'status') ? 'status' : null,
        ]));
    }

    /** Cột đầu tiên tồn tại */
    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $col) {
            if ($this->safeHasColumn($table, $col)) return $col;
        }
        return null;
    }

    /** VND */
    private function formatPrice($price): string
    {
        $n = is_numeric($price) ? (float)$price : 0.0;
        return number_format($n, 0, ',', '.') . ' đ';
    }

    /** URL ảnh ưu tiên tuyệt đối; tự ghép storage nếu cần */
    private function imageUrl($product, ?string $imgCol): ?string
    {
        $raw = ($imgCol && isset($product->{$imgCol})) ? (string)$product->{$imgCol} : '';
        if ($raw === '') return null;

        // Nếu đã là URL tuyệt đối -> trả luôn
        if (preg_match('~^https?://~i', $raw)) return $raw;

        // Nếu là path tương đối (VD: storage/products/a.jpg)
        try {
            // dùng url() để tạo absolute URL
            return url($raw);
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    /** hasColumn an toàn */
    private function safeHasColumn(string $table, string $column): bool
    {
        try { return Schema::hasColumn($table, $column); }
        catch (\Throwable $e) { return false; }
    }

    /**
     * Gọi Gemini với fallback tự động:
     * - Thử {apiVer}/{model} trong .env
     * - Nếu lỗi not found/unsupported -> flip v1 <-> v1beta & tự dò model hợp lệ
     */
    private function geminiGenerateTextFromContents(array $geminiContents, string $apiKey, string $apiVer, string $model): array
    {
        $callGenerate = function (string $ver, string $mdl, string $key, array $contents): array {
            $url = "https://generativelanguage.googleapis.com/{$ver}/models/{$mdl}:generateContent?key={$key}";
            $payload = json_encode(['contents' => $contents], JSON_UNESCAPED_UNICODE);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
            ]);
            $body = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($err) return [null, "cURL: {$err}"];

            $json = json_decode((string)$body, true) ?: [];
            if ($http < 200 || $http >= 300 || isset($json['error'])) {
                $msg = $json['error']['message'] ?? "HTTP {$http}: {$body}";
                return [null, $msg];
            }

            $text = '';
            foreach (($json['candidates'][0]['content']['parts'] ?? []) as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
            if ($text === '') return [null, 'Empty candidates'];
            return [$text, null];
        };

        $listModels = function (string $ver, string $key): array {
            $url = "https://generativelanguage.googleapis.com/{$ver}/models?key={$key}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
            ]);
            $body = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($err) return [null, "cURL: {$err}"];
            if ($http < 200 || $http >= 300) return [null, "HTTP {$http}: {$body}"];

            $json = json_decode((string)$body, true) ?: [];
            return [$json['models'] ?? [], null];
        };

        $supportsGen = function ($m): bool {
            $arr = $m['supportedGenerationMethods'] ?? $m['generationMethods'] ?? [];
            return in_array('generateContent', $arr, true) || in_array('generate_content', $arr, true);
        };

        $pickModel = function (array $models) use ($supportsGen): ?string {
            $prefer = [
                'gemini-1.5-pro',
                'gemini-1.5-flash',
                'gemini-1.5-flash-8b',
                'gemini-2.0-flash',
                'gemini-2.0-flash-lite',
            ];
            foreach ($prefer as $want) {
                foreach ($models as $m) {
                    if (($m['name'] ?? '') === "models/{$want}" && $supportsGen($m)) {
                        return $want;
                    }
                }
            }
            foreach ($models as $m) {
                if ($supportsGen($m) && isset($m['name'])) {
                    $n = (string)$m['name']; // "models/xxx"
                    return str_starts_with($n, 'models/') ? substr($n, 7) : $n;
                }
            }
            return null;
        };

        $shouldFlipVer = function (?string $errMsg): bool {
            if (!$errMsg) return false;
            $s = strtolower($errMsg);
            return str_contains($s, 'not found') || str_contains($s, 'unsupported') || str_contains($s, 'is not found for api version');
        };

        // 1) Thử theo ENV
        [$text, $err] = $callGenerate($apiVer, $model, $apiKey, $geminiContents);
        if (!$err) return [$text, null];

        // 2) Nếu lỗi “not found/unsupported” -> flip v1/v1beta + tự dò model
        if ($shouldFlipVer($err)) {
            $altVer = ($apiVer === 'v1') ? 'v1beta' : 'v1';
            [$models, $eList] = $listModels($altVer, $apiKey);
            if ($eList) return [null, $eList];

            $picked = $pickModel($models ?? []);
            if (!$picked) return [null, 'No Gemini model supports generateContent for your key/version'];

            return $callGenerate($altVer, $picked, $apiKey, $geminiContents);
        }

        return [null, $err];
    }
}
