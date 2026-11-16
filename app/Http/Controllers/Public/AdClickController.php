<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use Illuminate\Http\Request;

class AdClickController extends Controller
{
    public function redirect(Request $request, Advertisement $ad)
    {
        abort_if(blank($ad->destination_url), 404);

        $url = $ad->destination_url;

        abort_unless(str_starts_with($url, 'https://') || str_starts_with($url, 'http://'), 422);

        $ad->increment('clicks');

        return redirect()->away($url, 302);
    }
}
