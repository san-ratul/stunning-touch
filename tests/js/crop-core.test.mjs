/**
 * Node-based unit tests for the Image Resizer crop core
 * (resources/js/tools/crop-core.js).
 *
 * Run with:  node --test tests/js/*.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import {
    MIN_CROP_DIM,
    POSITION_PRESETS,
    applyPreset,
    clamp,
    clampCropRect,
    cropFromRequested,
    heightFromWidth,
    initialCrop,
    matchPreset,
    moveCrop,
    ratioFor,
    resizeCrop,
    widthFromHeight,
} from '../../resources/js/tools/crop-core.js';

test('clamp confines values to the bounds', () => {
    assert.equal(clamp(5, 0, 10), 5);
    assert.equal(clamp(-3, 0, 10), 0);
    assert.equal(clamp(42, 0, 10), 10);
});

test('initial crop selection covers the whole image', () => {
    assert.deepEqual(initialCrop(4000, 3000), { x: 0, y: 0, w: 4000, h: 3000 });
    assert.deepEqual(initialCrop(640, 640), { x: 0, y: 0, w: 640, h: 640 });
});

test('clampCropRect rounds to integers and clamps into the image', () => {
    assert.deepEqual(
        clampCropRect({ x: 10.6, y: 4.2, w: 100.4, h: 50.5 }, 400, 300),
        { x: 11, y: 4, w: 100, h: 51 },
    );
    assert.deepEqual(
        clampCropRect({ x: -50, y: -50, w: 500, h: 400 }, 400, 300),
        { x: 0, y: 0, w: 400, h: 300 },
    );
    assert.deepEqual(
        clampCropRect({ x: 350, y: 250, w: 200, h: 200 }, 400, 300),
        { x: 200, y: 100, w: 200, h: 200 },
    );
});

test('clampCropRect enforces minimum dimensions, bounded by tiny images', () => {
    assert.deepEqual(
        clampCropRect({ x: 0, y: 0, w: 4, h: 4 }, 400, 300),
        { x: 0, y: 0, w: MIN_CROP_DIM, h: MIN_CROP_DIM },
    );
    // A 10x10 image cannot host a 16px crop — the image size wins.
    assert.deepEqual(
        clampCropRect({ x: 0, y: 0, w: 2, h: 2 }, 10, 10),
        { x: 0, y: 0, w: 10, h: 10 },
    );
});

test('ratioFor returns width divided by height', () => {
    assert.equal(ratioFor({ x: 0, y: 0, w: 200, h: 100 }), 2);
});

test('heightFromWidth and widthFromHeight preserve the ratio with rounding', () => {
    assert.equal(heightFromWidth(300, 1.5), 200);
    assert.equal(widthFromHeight(200, 1.5), 300);
    assert.equal(heightFromWidth(100, 3), 33);
});

test('cropFromRequested clamps oversized requests into the image, keeping the center', () => {
    const prev = { x: 0, y: 0, w: 100, h: 100 };
    // Requesting more than the image offers clamps the box to the image…
    const rect = cropFromRequested(prev, 8000, 6000, 400, 300);
    assert.deepEqual(rect, { x: 0, y: 0, w: 400, h: 300 });
});

test('cropFromRequested keeps the previous center when shrinking', () => {
    const prev = { x: 200, y: 150, w: 100, h: 100 }; // center (250, 200) of 400x300
    const rect = cropFromRequested(prev, 50, 50, 400, 300);
    assert.deepEqual(rect, { x: 225, y: 175, w: 50, h: 50 });
});

test('cropFromRequested never lets the box leave the image', () => {
    const prev = { x: 0, y: 0, w: 300, h: 200 };
    const rect = cropFromRequested(prev, 100, 100, 400, 300);
    assert.ok(rect.x >= 0 && rect.y >= 0);
    assert.ok(rect.x + rect.w <= 400 && rect.y + rect.h <= 300);
});

test('moveCrop translates by the delta and clamps at the edges', () => {
    const rect = { x: 100, y: 100, w: 100, h: 100 };
    assert.deepEqual(moveCrop(rect, 50, -25, 400, 300), { x: 150, y: 75, w: 100, h: 100 });
    assert.deepEqual(moveCrop(rect, 10_000, 10_000, 400, 300), { x: 300, y: 200, w: 100, h: 100 });
    assert.deepEqual(moveCrop(rect, -10_000, -10_000, 400, 300), { x: 0, y: 0, w: 100, h: 100 });
});

test('moveCrop never changes the crop dimensions', () => {
    const rect = { x: 10, y: 10, w: 120, h: 80 };
    const moved = moveCrop(rect, 33, 44, 400, 300);
    assert.equal(moved.w, rect.w);
    assert.equal(moved.h, rect.h);
});

test('all nine position presets place the box without changing dimensions', () => {
    const rect = { x: 0, y: 0, w: 100, h: 50 };

    assert.deepEqual(applyPreset(rect, 'center', 400, 300), { x: 150, y: 125, w: 100, h: 50 });
    assert.deepEqual(applyPreset(rect, 'top', 400, 300), { x: 150, y: 0, w: 100, h: 50 });
    assert.deepEqual(applyPreset(rect, 'bottom', 400, 300), { x: 150, y: 250, w: 100, h: 50 });
    assert.deepEqual(applyPreset(rect, 'left', 400, 300), { x: 0, y: 125, w: 100, h: 50 });
    assert.deepEqual(applyPreset(rect, 'right', 400, 300), { x: 300, y: 125, w: 100, h: 50 });
    assert.deepEqual(applyPreset(rect, 'top-left', 400, 300), { x: 0, y: 0, w: 100, h: 50 });
    assert.deepEqual(applyPreset(rect, 'top-right', 400, 300), { x: 300, y: 0, w: 100, h: 50 });
    assert.deepEqual(applyPreset(rect, 'bottom-left', 400, 300), { x: 0, y: 250, w: 100, h: 50 });
    assert.deepEqual(applyPreset(rect, 'bottom-right', 400, 300), { x: 300, y: 250, w: 100, h: 50 });
});

test('position preset buttons map to all nine placements', () => {
    assert.deepEqual(POSITION_PRESETS, [
        'top-left', 'top', 'top-right',
        'left', 'center', 'right',
        'bottom-left', 'bottom', 'bottom-right',
    ]);
});

test('matchPreset recognizes preset positions and reports null for custom ones', () => {
    const imgW = 400;
    const imgH = 300;
    const centered = applyPreset({ x: 0, y: 0, w: 100, h: 50 }, 'center', imgW, imgH);
    assert.equal(matchPreset(centered, imgW, imgH), 'center');

    const topLeft = applyPreset({ x: 0, y: 0, w: 100, h: 50 }, 'top-left', imgW, imgH);
    assert.equal(matchPreset(topLeft, imgW, imgH), 'top-left');

    const custom = { x: 17, y: 93, w: 100, h: 50 };
    assert.equal(matchPreset(custom, imgW, imgH), null);

    // Within one pixel of a preset still counts as that preset.
    const near = { x: 151, y: 125, w: 100, h: 50 };
    assert.equal(matchPreset(near, imgW, imgH), 'center');
});

test('free resize from each edge grows and shrinks the right dimension', () => {
    const rect = { x: 100, y: 100, w: 100, h: 100 };

    assert.deepEqual(
        resizeCrop(rect, 'e', { x: 250, y: 0 }, 400, 300),
        { x: 100, y: 100, w: 150, h: 100 },
    );
    assert.deepEqual(
        resizeCrop(rect, 'e', { x: 150, y: 0 }, 400, 300),
        { x: 100, y: 100, w: 50, h: 100 },
    );
    assert.deepEqual(
        resizeCrop(rect, 'w', { x: 50, y: 0 }, 400, 300),
        { x: 50, y: 100, w: 150, h: 100 },
    );
    assert.deepEqual(
        resizeCrop(rect, 's', { x: 0, y: 280 }, 400, 300),
        { x: 100, y: 100, w: 100, h: 180 },
    );
    assert.deepEqual(
        resizeCrop(rect, 'n', { x: 0, y: 130 }, 400, 300),
        { x: 100, y: 130, w: 100, h: 70 },
    );
});

test('corner resize moves two edges at once around the fixed anchor', () => {
    const rect = { x: 100, y: 100, w: 100, h: 100 };
    // SE handle dragged to (300, 260): anchor is the NW corner (100, 100).
    assert.deepEqual(
        resizeCrop(rect, 'se', { x: 300, y: 260 }, 400, 300),
        { x: 100, y: 100, w: 200, h: 160 },
    );
    // NW handle dragged to (60, 60): anchor is the SE corner (200, 200).
    assert.deepEqual(
        resizeCrop(rect, 'nw', { x: 60, y: 60 }, 400, 300),
        { x: 60, y: 60, w: 140, h: 140 },
    );
});

test('free resize clamps at image boundaries and minimum size', () => {
    const rect = { x: 100, y: 100, w: 100, h: 100 };
    // Beyond the right boundary — clamped to the image edge.
    assert.deepEqual(
        resizeCrop(rect, 'e', { x: 5_000, y: 0 }, 400, 300),
        { x: 100, y: 100, w: 300, h: 100 },
    );
    // Below the minimum dimension — clamped up to MIN_CROP_DIM.
    assert.deepEqual(
        resizeCrop(rect, 'se', { x: 104, y: 104 }, 400, 300),
        { x: 100, y: 100, w: MIN_CROP_DIM, h: MIN_CROP_DIM },
    );
    // West edge cannot cross the left image boundary.
    assert.deepEqual(
        resizeCrop(rect, 'w', { x: -500, y: 0 }, 400, 300),
        { x: 0, y: 100, w: 200, h: 100 },
    );
});

test('ratio-locked resize from a corner keeps the aspect ratio', () => {
    const rect = { x: 100, y: 100, w: 100, h: 100 };
    const result = resizeCrop(rect, 'se', { x: 300, y: 100 }, 4000, 3000, 1);
    assert.equal(result.w, result.h);
    assert.equal(result.x, 100);
    assert.equal(result.y, 100);
});

test('ratio-locked resize from a width edge derives the height', () => {
    const rect = { x: 100, y: 100, w: 100, h: 100 };
    const result = resizeCrop(rect, 'e', { x: 400, y: 0 }, 4000, 3000, 2);
    assert.equal(result.w, 300);
    assert.equal(result.h, 150);
    assert.equal(result.x, 100);
});

test('ratio-locked resize from a height edge derives the width', () => {
    const rect = { x: 100, y: 100, w: 100, h: 100 };
    const result = resizeCrop(rect, 's', { x: 0, y: 400 }, 4000, 3000, 2);
    assert.equal(result.h, 300);
    assert.equal(result.w, 600);
    assert.equal(result.y, 100);
});

test('ratio-locked resize clamps to boundaries without breaking the ratio', () => {
    const rect = { x: 100, y: 100, w: 100, h: 100 };
    // Dragging far beyond the image: the box cannot exceed the space from
    // the anchor to the boundary, and the ratio must still hold.
    const result = resizeCrop(rect, 'se', { x: 10_000, y: 10_000 }, 400, 300, 1);
    assert.ok(result.w <= 300 && result.h <= 200);
    assert.equal(result.w, result.h);
    assert.ok(result.x >= 0 && result.y >= 0);
    assert.ok(result.x + result.w <= 400 && result.y + result.h <= 300);
});

test('ratio-locked resize enforces the minimum size on both axes', () => {
    const rect = { x: 100, y: 100, w: 100, h: 100 };
    const result = resizeCrop(rect, 'nw', { x: 198, y: 198 }, 4000, 3000, 1);
    assert.ok(result.w >= MIN_CROP_DIM);
    assert.ok(result.h >= MIN_CROP_DIM);
    assert.equal(result.w, result.h);
});

test('position changes never alter dimensions even with ratio locked', () => {
    const rect = { x: 40, y: 40, w: 160, h: 90 };
    for (const preset of POSITION_PRESETS) {
        const placed = applyPreset(rect, preset, 640, 480);
        assert.equal(placed.w, 160);
        assert.equal(placed.h, 90);
    }
});

test('every mutation of a valid rect stays integer, in-bounds and positive', () => {
    const imgW = 617;
    const imgH = 353;
    let rect = initialCrop(imgW, imgH);

    rect = cropFromRequested(rect, 311.7, 199.2, imgW, imgH);
    rect = moveCrop(rect, 123.45, -77.7, imgW, imgH);
    rect = resizeCrop(rect, 'se', { x: 600.6, y: 340.3 }, imgW, imgH, null);
    rect = applyPreset(rect, 'bottom-right', imgW, imgH);
    rect = resizeCrop(rect, 'w', { x: 5.5, y: 0 }, imgW, imgH, ratioFor(rect));

    assert.ok(Number.isInteger(rect.x) && Number.isInteger(rect.y));
    assert.ok(Number.isInteger(rect.w) && Number.isInteger(rect.h));
    assert.ok(rect.w >= 1 && rect.h >= 1);
    assert.ok(rect.x >= 0 && rect.y >= 0);
    assert.ok(rect.x + rect.w <= imgW);
    assert.ok(rect.y + rect.h <= imgH);
});

test('portrait, landscape and square images all resolve consistent crops', () => {
    const images = [
        [1080, 1920], // portrait
        [1920, 1080], // landscape
        [1000, 1000], // square
        [7, 9],       // smaller than the minimum crop
    ];

    for (const [imgW, imgH] of images) {
        let rect = initialCrop(imgW, imgH);
        assert.deepEqual(rect, { x: 0, y: 0, w: imgW, h: imgH });

        rect = cropFromRequested(rect, 40, 40, imgW, imgH);
        rect = applyPreset(rect, 'bottom-right', imgW, imgH);

        assert.ok(rect.w >= 1 && rect.h >= 1);
        assert.ok(rect.x + rect.w <= imgW);
        assert.ok(rect.y + rect.h <= imgH);
    }
});
