/**
 * Interactive crop editor for the Image Resizer.
 *
 * Pure DOM/interaction layer on top of crop-core.js. The DOM is built
 * once per image; every update mutates styles only (no re-rendering).
 * The crop box is positioned in percentages of the rendered image, so
 * window resizes and responsive layouts never need recalculating, and
 * pointer coordinates are converted to original-image pixels through
 * the live bounding rect — device pixel ratio and preview scaling are
 * therefore handled implicitly.
 *
 * Pointer Events cover mouse, touch and pen; handles are focusable
 * buttons with keyboard resizing for accessibility.
 */
import {
    applyPreset,
    clampCropRect,
    initialCrop,
    moveCrop,
    resizeCrop,
} from './crop-core';

const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];

const HANDLE_LABELS = {
    nw: 'Resize from top-left corner',
    n: 'Resize top edge',
    ne: 'Resize from top-right corner',
    e: 'Resize right edge',
    se: 'Resize from bottom-right corner',
    s: 'Resize bottom edge',
    sw: 'Resize from bottom-left corner',
    w: 'Resize left edge',
};

const KEY_STEP = 1;
const KEY_STEP_LARGE = 10;

export function createCropEditor(mount, image, { onChange = () => {} } = {}) {
    const imgW = image.naturalWidth;
    const imgH = image.naturalHeight;

    let crop = initialCrop(imgW, imgH);
    let ratio = null; // null = free resize, number = locked aspect ratio
    let gesture = null;

    // ---- Build the DOM once ----
    mount.classList.add('crop-stage');
    mount.setAttribute('role', 'group');
    mount.setAttribute('aria-label', 'Crop editor');

    const canvas = document.createElement('div');
    canvas.className = 'crop-canvas';

    const img = document.createElement('img');
    img.className = 'crop-image';
    img.src = image.src;
    img.alt = 'Image with crop selection';

    const box = document.createElement('div');
    box.className = 'crop-box';
    box.tabIndex = 0;
    box.setAttribute('aria-label', 'Crop selection. Drag to move, use arrow keys to nudge.');

    const grid = document.createElement('div');
    grid.className = 'crop-grid';
    grid.setAttribute('aria-hidden', 'true');

    const dims = document.createElement('span');
    dims.className = 'crop-dims';
    dims.setAttribute('aria-hidden', 'true');

    box.append(grid, dims);

    const handleEls = {};
    for (const h of HANDLES) {
        const handle = document.createElement('button');
        handle.type = 'button';
        handle.className = `crop-handle crop-handle-${h}`;
        handle.dataset.handle = h;
        handle.setAttribute('aria-label', HANDLE_LABELS[h]);
        box.appendChild(handle);
        handleEls[h] = handle;
    }

    canvas.append(img, box);
    mount.append(canvas);

    // ---- Rendering ----
    function paint() {
        box.style.left = `${(crop.x / imgW) * 100}%`;
        box.style.top = `${(crop.y / imgH) * 100}%`;
        box.style.width = `${(crop.w / imgW) * 100}%`;
        box.style.height = `${(crop.h / imgH) * 100}%`;

        dims.textContent = `${crop.w} × ${crop.h}`;
        const rect = canvas.getBoundingClientRect();
        const shownW = (crop.w / imgW) * rect.width;
        const shownH = (crop.h / imgH) * rect.height;
        dims.hidden = shownW < 72 || shownH < 36;
    }

    function commit(source) {
        paint();
        onChange({ ...crop }, source);
    }

    // ---- Pointer interaction ----
    function imageScale() {
        const rect = canvas.getBoundingClientRect();
        return rect.width > 0 ? imgW / rect.width : 1;
    }

    function canvasRect() {
        return canvas.getBoundingClientRect();
    }

    function pointerToImage(e, rect) {
        return {
            x: (e.clientX - rect.left) * (imgW / rect.width),
            y: (e.clientY - rect.top) * (imgH / rect.height),
        };
    }

    function onPointerDown(e) {
        if (e.button !== undefined && e.button !== 0 && e.pointerType === 'mouse') return;

        const handle = e.target.closest('[data-handle]');
        const isHandle = Boolean(handle);

        gesture = {
            pointerId: e.pointerId,
            mode: isHandle ? 'resize' : 'move',
            handle: isHandle ? handle.dataset.handle : null,
            startCrop: { ...crop },
            rect: canvasRect(),
            startClientX: e.clientX,
            startClientY: e.clientY,
        };

        const captureTarget = isHandle ? handle : box;
        try {
            captureTarget.setPointerCapture(e.pointerId);
        } catch {
            /* older browsers without pointer capture still work on-move */
        }

        e.preventDefault();
    }

    function onPointerMove(e) {
        if (!gesture || e.pointerId !== gesture.pointerId) return;

        if (gesture.mode === 'move') {
            const scale = imgW / gesture.rect.width;
            const dx = (e.clientX - gesture.startClientX) * scale;
            const dy = (e.clientY - gesture.startClientY) * (imgH / gesture.rect.height);
            crop = moveCrop(gesture.startCrop, dx, dy, imgW, imgH);
        } else {
            crop = resizeCrop(
                gesture.startCrop,
                gesture.handle,
                pointerToImage(e, gesture.rect),
                imgW, imgH,
                ratio,
            );
        }

        commit(gesture.mode);
    }

    function onPointerEnd(e) {
        if (!gesture || e.pointerId !== gesture.pointerId) return;
        gesture = null;
        commit('end');
    }

    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointermove', onPointerMove);
    canvas.addEventListener('pointerup', onPointerEnd);
    canvas.addEventListener('pointercancel', onPointerEnd);
    canvas.addEventListener('dragstart', (e) => e.preventDefault());

    // Percentage positioning keeps the box correct across resizes; only
    // the size-readout visibility depends on the rendered size.
    window.addEventListener('resize', paint);

    // ---- Keyboard interaction ----
    function nudge(dx, dy) {
        crop = moveCrop(crop, dx, dy, imgW, imgH);
        commit('move');
    }

    function resizeByKey(handle, key) {
        // Synthesize a pointer just past the dragged edge so resizeCrop
        // applies the same anchored, ratio-aware math as pointer drags.
        // Left/Right drive the horizontal axis, Up/Down the vertical one;
        // the untouched edge stays at its current position.
        const step = key.shiftKey ? KEY_STEP_LARGE : KEY_STEP;
        const edgeX = handle.includes('w') ? crop.x : crop.x + crop.w;
        const edgeY = handle.includes('n') ? crop.y : crop.y + crop.h;
        let pointer;

        if (key.key === 'ArrowRight' || key.key === 'ArrowLeft') {
            const dir = (key.key === 'ArrowRight' ? 1 : -1) * (handle.includes('w') ? -1 : 1);
            pointer = { x: edgeX + dir * step, y: edgeY };
        } else {
            const dir = (key.key === 'ArrowDown' ? 1 : -1) * (handle.includes('n') ? -1 : 1);
            pointer = { x: edgeX, y: edgeY + dir * step };
        }

        crop = resizeCrop(crop, handle, pointer, imgW, imgH, ratio);
        commit('resize');
    }

    box.addEventListener('keydown', (e) => {
        if (e.target !== box) return;
        const step = e.shiftKey ? KEY_STEP_LARGE : KEY_STEP;
        switch (e.key) {
            case 'ArrowLeft': nudge(-step, 0); break;
            case 'ArrowRight': nudge(step, 0); break;
            case 'ArrowUp': nudge(0, -step); break;
            case 'ArrowDown': nudge(0, step); break;
            default: return;
        }
        e.preventDefault();
    });

    for (const h of HANDLES) {
        handleEls[h].addEventListener('keydown', (e) => {
            if (!e.key.startsWith('Arrow')) return;
            resizeByKey(h, e);
            e.preventDefault();
        });
    }

    // ---- Public API ----
    paint();

    return {
        getCrop() {
            return { ...crop };
        },

        /** Sync the selection from the width/height inputs (no events loop back). */
        setCrop(rect, source = 'set') {
            crop = clampCropRect(rect, imgW, imgH, 1, 1);
            commit(source);
        },

        /** null disables ratio lock; a number locks w/h to that ratio. */
        setRatio(value) {
            ratio = typeof value === 'number' && Number.isFinite(value) && value > 0 ? value : null;
        },

        applyPreset(preset) {
            crop = applyPreset(crop, preset, imgW, imgH);
            commit('preset');
        },

        reset() {
            crop = initialCrop(imgW, imgH);
            commit('reset');
        },

        destroy() {
            window.removeEventListener('resize', paint);
            mount.replaceChildren();
        },

        imageWidth: imgW,
        imageHeight: imgH,
    };
}
