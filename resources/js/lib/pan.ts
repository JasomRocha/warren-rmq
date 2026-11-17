/**
 * Lets the user move around a scrollable area by dragging: with the middle mouse button (the scroll wheel
 * pressed) anywhere, or with the left button on the background (never on a card, which is clickable).
 * Returns a function that removes the listeners.
 */
export function enablePanning(el: HTMLElement): () => void {
    let drag: { x: number; y: number; left: number; top: number } | null = null;

    // Stops the browser's own "autoscroll" circle that a middle click would otherwise start.
    const onMouseDown = (e: MouseEvent): void => {
        if (e.button === 1) e.preventDefault();
    };

    const onDown = (e: PointerEvent): void => {
        const onBackground = e.button === 0 && !(e.target as Element | null)?.closest('.node');
        if (e.button !== 1 && !onBackground) return;

        // A click on the scrollbars must keep scrolling normally.
        const box = el.getBoundingClientRect();
        if (e.clientX > box.left + el.clientWidth || e.clientY > box.top + el.clientHeight) return;

        drag = { x: e.clientX, y: e.clientY, left: el.scrollLeft, top: el.scrollTop };
        el.classList.add('panning');
        el.setPointerCapture?.(e.pointerId);
        e.preventDefault();
    };

    const onMove = (e: PointerEvent): void => {
        if (!drag) return;
        el.scrollLeft = drag.left - (e.clientX - drag.x);
        el.scrollTop = drag.top - (e.clientY - drag.y);
    };

    const onUp = (): void => {
        drag = null;
        el.classList.remove('panning');
    };

    el.addEventListener('mousedown', onMouseDown);
    el.addEventListener('pointerdown', onDown);
    el.addEventListener('pointermove', onMove);
    el.addEventListener('pointerup', onUp);
    el.addEventListener('pointercancel', onUp);

    return () => {
        el.removeEventListener('mousedown', onMouseDown);
        el.removeEventListener('pointerdown', onDown);
        el.removeEventListener('pointermove', onMove);
        el.removeEventListener('pointerup', onUp);
        el.removeEventListener('pointercancel', onUp);
        onUp();
    };
}
