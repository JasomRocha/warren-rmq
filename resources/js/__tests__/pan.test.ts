import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enablePanning } from '../lib/pan';

function setup() {
    const el = document.createElement('div');
    el.innerHTML = '<div class="stage"><button class="node">card</button></div>';
    document.body.append(el);
    let left = 100;
    let top = 50;
    Object.defineProperty(el, 'scrollLeft', { get: () => left, set: (v: number) => { left = v; }, configurable: true });
    Object.defineProperty(el, 'scrollTop', { get: () => top, set: (v: number) => { top = v; }, configurable: true });
    Object.defineProperty(el, 'clientWidth', { value: 800, configurable: true });
    Object.defineProperty(el, 'clientHeight', { value: 600, configurable: true });
    el.getBoundingClientRect = () => ({ left: 0, top: 0, right: 815, bottom: 615, width: 815, height: 615, x: 0, y: 0, toJSON: () => ({}) });
    el.setPointerCapture = vi.fn();
    const stop = enablePanning(el);
    const fire = (target: Element, type: string, init: MouseEventInit) => {
        const event = new MouseEvent(type, { bubbles: true, cancelable: true, ...init });
        target.dispatchEvent(event);
        return event;
    };
    return { el, stop, fire, scroll: () => ({ left, top }) };
}

describe('arrastar o fluxo', () => {
    let ctx: ReturnType<typeof setup>;
    beforeEach(() => { ctx = setup(); });
    afterEach(() => { ctx.stop(); ctx.el.remove(); });

    it('com o botão do meio, em qualquer lugar (até em cima de um cartão), move a tela no sentido contrário ao do mouse', () => {
        const card = ctx.el.querySelector('.node')!;
        ctx.fire(card, 'pointerdown', { button: 1, clientX: 300, clientY: 300 });
        ctx.fire(ctx.el, 'pointermove', { clientX: 250, clientY: 320 }); // mouse 50 px para a esquerda e 20 para baixo

        expect(ctx.scroll()).toEqual({ left: 150, top: 30 }); // a tela acompanha a mão: o conteúdo vai para a esquerda
        expect(ctx.el.classList.contains('panning')).toBe(true);

        ctx.fire(ctx.el, 'pointerup', {});
        expect(ctx.el.classList.contains('panning')).toBe(false);

        ctx.fire(ctx.el, 'pointermove', { clientX: 0, clientY: 0 });
        expect(ctx.scroll()).toEqual({ left: 150, top: 30 }); // soltou: não move mais
    });

    it('com o botão esquerdo também arrasta, mas só pelo fundo', () => {
        ctx.fire(ctx.el.querySelector('.stage')!, 'pointerdown', { button: 0, clientX: 200, clientY: 200 });
        ctx.fire(ctx.el, 'pointermove', { clientX: 180, clientY: 200 });
        expect(ctx.scroll().left).toBe(120);
    });

    it('o botão esquerdo sobre um cartão não arrasta, para o clique continuar abrindo os detalhes', () => {
        ctx.fire(ctx.el.querySelector('.node')!, 'pointerdown', { button: 0, clientX: 200, clientY: 200 });
        ctx.fire(ctx.el, 'pointermove', { clientX: 100, clientY: 100 });

        expect(ctx.scroll()).toEqual({ left: 100, top: 50 });
    });

    it('não atrapalha as barras de rolagem', () => {
        ctx.fire(ctx.el, 'pointerdown', { button: 1, clientX: 810, clientY: 300 }); // além da área útil: é a barra vertical
        ctx.fire(ctx.el, 'pointermove', { clientX: 700, clientY: 300 });

        expect(ctx.scroll()).toEqual({ left: 100, top: 50 });
    });

    it('impede a rolagem automática do navegador ao apertar o botão do meio', () => {
        expect(ctx.fire(ctx.el, 'mousedown', { button: 1 }).defaultPrevented).toBe(true);
        expect(ctx.fire(ctx.el, 'mousedown', { button: 0 }).defaultPrevented).toBe(false);
    });

    it('outros botões não fazem nada', () => {
        ctx.fire(ctx.el, 'pointerdown', { button: 2, clientX: 300, clientY: 300 });
        ctx.fire(ctx.el, 'pointermove', { clientX: 100, clientY: 100 });

        expect(ctx.scroll()).toEqual({ left: 100, top: 50 });
    });

    it('depois de desligado, para de responder', () => {
        ctx.stop();
        ctx.fire(ctx.el, 'pointerdown', { button: 1, clientX: 300, clientY: 300 });
        ctx.fire(ctx.el, 'pointermove', { clientX: 100, clientY: 100 });

        expect(ctx.scroll()).toEqual({ left: 100, top: 50 });
    });
});
