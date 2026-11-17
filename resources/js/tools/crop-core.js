/**
 * Pure crop geometry for the Image Resizer crop editor.
 *
 * All crop rects are INTEGER rectangles in original-image pixel space:
 *   { x, y, w, h } with x >= 0, y >= 0, w/h >= 1, x + w <= imgW, y + h <= imgH.
 * Keeping every mutation integer-exact prevents rounding drift from
 * shifting or expanding the crop between the editor, the input fields
 * and the final canvas draw.
 *
 * No DOM here — this module is unit-tested with node --test.
 */

/** Smallest crop side the UI and processing accept (px). */
export const MIN_CROP_DIM = 16;

/** Position presets, keyed like the buttons in the tool view. */
export const POSITION_PRESETS = [
    'top-left', 'top', 'top-right',
    'left', 'center', 'right',
    'bottom-left', 'bottom', 'bottom-right',
];

export function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

/**
 * Normalize a crop rect to valid, integer, in-bounds form.
 * Dimensions are clamped first, then the position is clamped so the
 * rect always stays inside the image.
 */
export function clampCropRect(rect, imgW, imgH, minW = MIN_CROP_DIM, minH = MIN_CROP_DIM) {
    const minW2 = Math.max(1, Math.min(minW, imgW));
    const minH2 = Math.max(1, Math.min(minH, imgH));

    const w = clamp(Math.round(rect.w), minW2, imgW);
    const h = clamp(Math.round(rect.h), minH2, imgH);
    const x = clamp(Math.round(rect.x), 0, imgW - w);
    const y = clamp(Math.round(rect.y), 0, imgH - h);

    return { x, y, w, h };
}

/** The editor's initial selection: the whole image. */
export function initialCrop(imgW, imgH) {
    return clampCropRect({ x: 0, y: 0, w: imgW, h: imgH }, imgW, imgH, 1, 1);
}

/** Aspect ratio of a crop rect (width / height). Always > 0. */
export function ratioFor(cropRect) {
    return cropRect.w / cropRect.h;
}

/** Height that preserves `ratio` for a given width. */
export function heightFromWidth(width, ratio) {
    return Math.max(1, Math.round(width / ratio));
}

/** Width that preserves `ratio` for a given height. */
export function widthFromHeight(height, ratio) {
    return Math.max(1, Math.round(height * ratio));
}

/**
 * Crop rect for a requested output size, clamped into the image.
 * The rect keeps its previous center where possible, so typing new
 * dimensions moves the box as little as the bounds allow.
 */
export function cropFromRequested(prev, reqW, reqH, imgW, imgH, minW = MIN_CROP_DIM, minH = MIN_CROP_DIM) {
    const next = clampCropRect(
        { x: 0, y: 0, w: reqW, h: reqH },
        imgW, imgH, minW, minH,
    );

    const cx = prev.x + prev.w / 2;
    const cy = prev.y + prev.h / 2;
    next.x = clamp(Math.round(cx - next.w / 2), 0, imgW - next.w);
    next.y = clamp(Math.round(cy - next.h / 2), 0, imgH - next.h);

    return next;
}

/** Translate a crop rect by (dx, dy) image pixels, clamped to the image. */
export function moveCrop(cropRect, dx, dy, imgW, imgH) {
    return clampCropRect(
        { x: cropRect.x + dx, y: cropRect.y + dy, w: cropRect.w, h: cropRect.h },
        imgW, imgH, cropRect.w, cropRect.h,
    );
}

/** Reposition a crop rect per a position preset. Dimensions never change. */
export function applyPreset(cropRect, preset, imgW, imgH) {
    let x;
    if (preset.includes('left')) x = 0;
    else if (preset.includes('right')) x = imgW - cropRect.w;
    else x = Math.round((imgW - cropRect.w) / 2);

    let y;
    if (preset.startsWith('top')) y = 0;
    else if (preset.startsWith('bottom')) y = imgH - cropRect.h;
    else y = Math.round((imgH - cropRect.h) / 2);

    return clampCropRect({ x, y, w: cropRect.w, h: cropRect.h }, imgW, imgH, cropRect.w, cropRect.h);
}

/**
 * Which preset (if any) matches the rect's position within `tolerance`
 * pixels. Edge presets are checked before corners so a full-width box
 * highlights "top" rather than "top-left". Returns null for custom
 * positions so the UI never shows a misleading preset state.
 */
export function matchPreset(cropRect, imgW, imgH, tolerance = 1) {
    const order = ['center', 'top', 'bottom', 'left', 'right',
        'top-left', 'top-right', 'bottom-left', 'bottom-right'];

    for (const preset of order) {
        const placed = applyPreset(cropRect, preset, imgW, imgH);
        if (Math.abs(placed.x - cropRect.x) <= tolerance && Math.abs(placed.y - cropRect.y) <= tolerance) {
            return preset;
        }
    }

    return null;
}

/**
 * Resize a crop rect by dragging `handle` ('nw','n','ne','e','se','s','sw','w')
 * toward `pointer` (position in image pixels). The opposite edge/corner is
 * the fixed anchor. When `ratio` is a number the box keeps that aspect
 * ratio; when null both dimensions resize independently. The result is
 * always integer, at least minW/minH, and fully inside the image.
 */
export function resizeCrop(cropRect, handle, pointer, imgW, imgH, ratio = null, minW = MIN_CROP_DIM, minH = MIN_CROP_DIM) {
    const minW2 = Math.max(1, Math.min(minW, imgW));
    const minH2 = Math.max(1, Math.min(minH, imgH));

    const affectsX = handle.includes('e') || handle.includes('w') || handle.length === 2;
    const affectsY = handle.includes('n') || handle.includes('s') || handle.length === 2;

    // Anchor = opposite edge/corner, and room from the anchor to each
    // image boundary. Both are always >= the current size, so min <= max.
    const anchorX = handle.includes('w') ? cropRect.x + cropRect.w : cropRect.x;
    const anchorY = handle.includes('n') ? cropRect.y + cropRect.h : cropRect.y;
    const maxW = handle.includes('w') ? anchorX : imgW - anchorX;
    const maxH = handle.includes('n') ? anchorY : imgH - anchorY;

    let w = cropRect.w;
    let h = cropRect.h;

    if (ratio === null) {
        if (affectsX) w = clamp(Math.round(Math.abs(pointer.x - anchorX)), minW2, maxW);
        if (affectsY) h = clamp(Math.round(Math.abs(pointer.y - anchorY)), minH2, maxH);
    } else {
        // Width-affecting handles drive the width; n/s handles drive the
        // height. The derived dimension is clamped and fed back so the
        // ratio stays exact and both bounds hold.
        const drivesWidth = affectsX;
        if (drivesWidth) {
            w = clamp(Math.round(Math.abs(pointer.x - anchorX)), minW2, maxW);
            h = clamp(Math.round(w / ratio), minH2, maxH);
            w = clamp(Math.round(h * ratio), minW2, maxW);
            h = clamp(Math.round(w / ratio), minH2, maxH);
        } else {
            h = clamp(Math.round(Math.abs(pointer.y - anchorY)), minH2, maxH);
            w = clamp(Math.round(h * ratio), minW2, maxW);
            h = clamp(Math.round(w / ratio), minH2, maxH);
        }
    }

    const x = handle.includes('w') ? anchorX - w : anchorX;
    const y = handle.includes('n') ? anchorY - h : anchorY;

    return { x, y, w, h };
}
