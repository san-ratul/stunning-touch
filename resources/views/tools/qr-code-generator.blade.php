@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <label class="label" for="qr-text">Text or URL</label>
    <input id="qr-text" type="text" class="input" placeholder="https://stunningtouch.com or any text" data-text maxlength="2000">

    <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <label class="label" for="qr-size">Size (px)</label>
            <select id="qr-size" class="input" data-size>
                @foreach([128, 200, 256, 320, 512, 1024] as $size)
                    <option value="{{ $size }}" {{ $size === 256 ? 'selected' : '' }}>{{ $size }} px</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="qr-margin">Quiet zone (margin)</label>
            <select id="qr-margin" class="input" data-margin>
                @foreach([0, 1, 2, 4, 8] as $margin)
                    <option value="{{ $margin }}" {{ $margin === 2 ? 'selected' : '' }}>{{ $margin }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="qr-ecc">Error correction</label>
            <select id="qr-ecc" class="input" data-ecc>
                <option value="L">L — Low (~7%)</option>
                <option value="M">M — Medium (~15%)</option>
                <option value="Q">Q — Quartile (~25%)</option>
                <option value="H" selected>H — High (~30%)</option>
            </select>
        </div>
        <div>
            <label class="label" for="qr-fg">Foreground</label>
            <input id="qr-fg" type="color" value="#0f172a" class="input h-[42px] p-1" data-fg>
        </div>
        <div>
            <label class="label" for="qr-bg">Background</label>
            <input id="qr-bg" type="color" value="#ffffff" class="input h-[42px] p-1" data-bg>
        </div>
    </div>

    <div class="mt-6 flex flex-col items-center">
        <div hidden data-preview></div>
        <p class="text-sm text-slate-500" data-status>Enter text or a URL — the QR code updates automatically.</p>
        <div class="mt-5 flex flex-wrap justify-center gap-2">
            <button type="button" class="btn-primary" data-download-png disabled>Download PNG</button>
            <button type="button" class="btn-secondary" data-download-svg disabled>Download SVG</button>
        </div>
    </div>
</div>
@endsection
