/* Фон-параллакс и плавающая кнопка. */
(() => {
  const heroBg = document.querySelector('[data-hero-bg]');
  const stickyAction = document.querySelector('[data-sticky-action]');
  const loomSection = document.querySelector('#questions');
  const leadSection = document.querySelector('#lead');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const wide = window.matchMedia('(min-width: 760px)');

  let targetScroll = window.scrollY;
  let renderedScroll = window.scrollY;
  let frame = 0;

  // Лён почти неподвижен (fixed-слой), лёгкий дрейф 12% — только desktop;
  // на мобильных и при reduced-motion фон стоит чистым CSS.
  function renderBackground(scrollY) {
    if (!heroBg) return;
    if (reduceMotion.matches || !wide.matches) {
      heroBg.style.transform = '';
      return;
    }
    heroBg.style.transform = `translate3d(0, ${(-scrollY * 0.12).toFixed(1)}px, 0)`;
  }

  function render() {
    renderedScroll += (targetScroll - renderedScroll) * 0.11;
    renderBackground(renderedScroll);

    if (stickyAction) {
      // Прячем плавающую кнопку, пока станок «Три вопроса» в центре экрана:
      // у каждого ковра там свой контекстный CTA, а сама кнопка ведёт на #questions.
      let inLoom = false;
      if (loomSection) {
        const r = loomSection.getBoundingClientRect();
        inLoom = r.top < window.innerHeight * 0.6 && r.bottom > window.innerHeight * 0.4;
      }
      // Долистали до финальной формы — кнопка отлипает: сам CTA уже перед глазами.
      let inLead = false;
      if (leadSection) {
        inLead = leadSection.getBoundingClientRect().top < window.innerHeight * 0.85;
      }
      const past = targetScroll > window.innerHeight * 0.72;
      stickyAction.classList.toggle('is-visible', past && !inLoom && !inLead);
    }

    const stillMoving = Math.abs(targetScroll - renderedScroll) > 0.1;
    frame = stillMoving ? requestAnimationFrame(render) : 0;
  }

  function requestRender() {
    if (!frame) frame = requestAnimationFrame(render);
  }

  window.addEventListener('scroll', () => {
    targetScroll = window.scrollY;
    requestRender();
  }, { passive: true });

  window.addEventListener('resize', () => {
    targetScroll = window.scrollY;
    requestRender();
  }, { passive: true });

  wide.addEventListener?.('change', () => {
    if (heroBg) heroBg.style.transform = '';
    requestRender();
  });

  reduceMotion.addEventListener?.('change', () => {
    if (heroBg && reduceMotion.matches) heroBg.style.transform = '';
    requestRender();
  });

  requestRender();
})();

/* Утверждённая WebGL-сцена трёх ковров. Геометрия, тайминг,
   фронтальная намотка и интервалы перенесены без изменений. */
(() => {
  'use strict';

  const scene = document.querySelector('.loom-story');
  const stage = document.querySelector('[data-loom-stage]');
  const visual = document.querySelector('[data-loom-visual]');
  const canvas = document.querySelector('[data-loom-canvas]');
  const warp = document.querySelector('[data-loom-warp]');
  const rod = document.querySelector('[data-loom-rod]');
  const rodEnds = [...document.querySelectorAll('[data-loom-rod-end]')];
  const shuttle = document.querySelector('[data-loom-shuttle]');
  const copyRoot = document.querySelector('[data-loom-copy-list]');
  const copyItems = [...document.querySelectorAll('[data-loom-copy]')];
  const current = document.querySelector('[data-loom-current]');
  const progressBar = document.querySelector('[data-loom-progress]');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const CARD_ASPECT = 1 / 1.42;

  if (!scene || !stage || !visual || !canvas || reducedMotion.matches) return;

  const gl = canvas.getContext('webgl2', {
    alpha: true,
    antialias: true,
    depth: true,
    powerPreference: 'high-performance',
    premultipliedAlpha: true,
  });

  if (!gl) return;

  try {
    const vertexSource = `#version 300 es
      precision highp float;

      in vec2 aPosition;
      in vec2 aUv;

      uniform vec2 uViewport;
      uniform float uHeight;
      uniform float uFringe;
      uniform float uRoll;
      uniform float uRadius;
      uniform float uGap;
      uniform float uExit;

      out vec2 vUv;
      out float vShade;
      out float vRolled;
      out float vOuterLayer;

      void main() {
        float s = aPosition.y;
        float cardAspect = ${CARD_ASPECT.toFixed(8)};
        float totalHeight = uHeight + uFringe;
        float widthNdc = uHeight * cardAspect * (uViewport.y / uViewport.x);
        float x = (aPosition.x - 0.5) * widthNdc;
        float rodY = 0.74 + uExit * 0.56;
        float feed = smoothstep(0.0, 0.16, uRoll);
        float winding = smoothstep(0.13, 1.0, uRoll);
        float rolledLength = winding * 0.992;
        float hangingGap = uGap * (1.0 - feed);
        float y;
        float z;
        float shade = 1.0;
        float outerLayer = 1.0;

        if (s < rolledLength) {
          float frontSpan = min(rolledLength, 3.14159265 * uRadius / totalHeight);
          float outerStart = rolledLength - frontSpan;
          float theta = min(((rolledLength - s) * totalHeight) / uRadius, 3.14159265);
          y = rodY - cos(theta) * uRadius;
          z = -sin(theta) * uRadius - 0.006;
          shade = 0.78 + 0.22 * sin(theta);
          outerLayer = step(outerStart, s);
        } else {
          float hanging = s - rolledLength;
          float bend = 1.0 - smoothstep(0.0, 0.052, hanging);
          y = rodY - uRadius - hangingGap - hanging * totalHeight + bend * 0.012;
          z = -bend * uRadius * 0.42;
          shade = 0.96 + bend * 0.04;
        }

        float perspective = 1.0 / (1.0 + max(z, 0.0) * 1.2);
        x *= perspective;

        vUv = aUv;
        vShade = shade;
        vRolled = s < rolledLength ? 1.0 : 0.0;
        vOuterLayer = outerLayer;
        gl_Position = vec4(x, y, z * 0.9, 1.0);
      }
    `;

    const fragmentSource = `#version 300 es
      precision highp float;

      uniform sampler2D uTexture;
      uniform float uReveal;
      uniform float uExit;

      in vec2 vUv;
      in float vShade;
      in float vRolled;
      in float vOuterLayer;

      out vec4 outColor;

      void main() {
        if (uReveal < 0.001) discard;

        float wovenEdge = 1.0 - uReveal + sin(vUv.x * 94.0) * 0.0025 + sin(vUv.x * 31.0) * 0.0018;
        float visible = smoothstep(wovenEdge - 0.004, wovenEdge + 0.004, vUv.y);
        if (visible < 0.01) discard;
        if (vRolled > 0.5 && vOuterLayer < 0.5) discard;

        float edgeGlow = 1.0 - smoothstep(0.0, 0.018, abs(vUv.y - wovenEdge));
        float exitAlpha = 1.0 - smoothstep(0.76, 1.0, uExit);
        vec4 texel = texture(uTexture, vUv);
        texel.rgb *= vShade;
        texel.rgb += edgeGlow * vec3(0.075, 0.038, 0.012);

        texel.a *= visible * exitAlpha;

        outColor = texel;
      }
    `;

    function compileShader(type, source) {
      const shader = gl.createShader(type);
      gl.shaderSource(shader, source);
      gl.compileShader(shader);
      if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        const message = gl.getShaderInfoLog(shader);
        gl.deleteShader(shader);
        throw new Error(message || 'Shader compilation failed');
      }
      return shader;
    }

    function createProgram() {
      const webglProgram = gl.createProgram();
      gl.attachShader(webglProgram, compileShader(gl.VERTEX_SHADER, vertexSource));
      gl.attachShader(webglProgram, compileShader(gl.FRAGMENT_SHADER, fragmentSource));
      gl.linkProgram(webglProgram);
      if (!gl.getProgramParameter(webglProgram, gl.LINK_STATUS)) {
        const message = gl.getProgramInfoLog(webglProgram);
        gl.deleteProgram(webglProgram);
        throw new Error(message || 'Program linking failed');
      }
      return webglProgram;
    }

    const program = createProgram();
    const vertexArray = gl.createVertexArray();
    gl.bindVertexArray(vertexArray);

    const columns = 18;
    const rows = 260;
    const vertices = [];
    const indices = [];

    for (let row = 0; row <= rows; row += 1) {
      const v = row / rows;
      for (let column = 0; column <= columns; column += 1) {
        const u = column / columns;
        vertices.push(u, v, u, v);
      }
    }

    for (let row = 0; row < rows; row += 1) {
      for (let column = 0; column < columns; column += 1) {
        const point = row * (columns + 1) + column;
        const next = point + columns + 1;
        indices.push(point, next, point + 1, point + 1, next, next + 1);
      }
    }

    const vertexBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, vertexBuffer);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array(vertices), gl.STATIC_DRAW);

    const indexBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, indexBuffer);
    gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, new Uint16Array(indices), gl.STATIC_DRAW);

    const stride = 4 * Float32Array.BYTES_PER_ELEMENT;
    const positionLocation = gl.getAttribLocation(program, 'aPosition');
    const uvLocation = gl.getAttribLocation(program, 'aUv');
    gl.enableVertexAttribArray(positionLocation);
    gl.vertexAttribPointer(positionLocation, 2, gl.FLOAT, false, stride, 0);
    gl.enableVertexAttribArray(uvLocation);
    gl.vertexAttribPointer(uvLocation, 2, gl.FLOAT, false, stride, 2 * Float32Array.BYTES_PER_ELEMENT);

    const uniforms = {
      viewport: gl.getUniformLocation(program, 'uViewport'),
      height: gl.getUniformLocation(program, 'uHeight'),
      fringe: gl.getUniformLocation(program, 'uFringe'),
      roll: gl.getUniformLocation(program, 'uRoll'),
      radius: gl.getUniformLocation(program, 'uRadius'),
      gap: gl.getUniformLocation(program, 'uGap'),
      reveal: gl.getUniformLocation(program, 'uReveal'),
      exit: gl.getUniformLocation(program, 'uExit'),
      texture: gl.getUniformLocation(program, 'uTexture'),
    };

    const textureUrls = ['card-1-fringed.webp', 'card-2-fringed.webp', 'card-3-fringed.webp'];

    const textures = textureUrls.map(() => {
      const texture = gl.createTexture();
      gl.bindTexture(gl.TEXTURE_2D, texture);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
      gl.texImage2D(
        gl.TEXTURE_2D,
        0,
        gl.RGBA,
        1,
        1,
        0,
        gl.RGBA,
        gl.UNSIGNED_BYTE,
        new Uint8Array([244, 238, 228, 255]),
      );
      return { texture, ready: false };
    });

    function showTextureFallback(error) {
      document.documentElement.classList.remove('has-loom-webgl');
      console.warn('WebGL carpet texture fallback enabled.', error);
    }

    textureUrls.forEach((url, index) => {
      const image = new Image();
      image.decoding = 'async';
      image.addEventListener('load', () => {
        try {
          gl.bindTexture(gl.TEXTURE_2D, textures[index].texture);
          gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image);
          gl.generateMipmap(gl.TEXTURE_2D);
          textures[index].ready = true;
          requestLoomRender();
        } catch (error) {
          showTextureFallback(error);
        }
      });
      image.addEventListener('error', showTextureFallback);
      image.src = url;
    });

    gl.useProgram(program);
    gl.uniform1i(uniforms.texture, 0);
    gl.enable(gl.DEPTH_TEST);
    gl.depthFunc(gl.LEQUAL);
    gl.enable(gl.BLEND);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);

    const clamp = (value, min = 0, max = 1) => Math.min(max, Math.max(min, value));
    const smoothstep = (start, end, value) => {
      const t = clamp((value - start) / (end - start));
      return t * t * (3 - 2 * t);
    };

    const timing = [
      { weaveStart: 0.0, weaveEnd: 0.14, rollStart: 0.20, rollEnd: 0.39 },
      { weaveStart: 0.25, weaveEnd: 0.41, rollStart: 0.47, rollEnd: 0.66 },
      { weaveStart: 0.52, weaveEnd: 0.68, rollStart: 0.75, rollEnd: 0.91 },
    ];

    let targetProgress = 0;
    let displayedProgress = 0;
    let loomFrame = 0;
    let activeCopy = 0;
    let cardHeightPixels = 0;
    let cardWidthPixels = 0;
    let fringeLengthPixels = 0;
    let visualHeight = 1;

    function resizeLoom() {
      const rect = visual.getBoundingClientRect();
      const dpr = Math.min(window.devicePixelRatio || 1, 1.5);
      const width = Math.max(1, Math.round(rect.width * dpr));
      const height = Math.max(1, Math.round(rect.height * dpr));

      if (canvas.width !== width || canvas.height !== height) {
        canvas.width = width;
        canvas.height = height;
        gl.viewport(0, 0, width, height);
      }

      visualHeight = Math.max(rect.height, 1);
      const desktop = window.innerWidth >= 760;
      const widthShare = desktop ? 0.80 : 0.96;
      const heightShare = desktop ? 0.82 : 0.74;
      cardHeightPixels = Math.min(visualHeight * heightShare, (rect.width * widthShare) / CARD_ASPECT);
      cardWidthPixels = cardHeightPixels * CARD_ASPECT;
      fringeLengthPixels = 0;
      visual.style.setProperty('--loom-card-height', `${cardHeightPixels}px`);
      visual.style.setProperty('--loom-card-width', `${cardWidthPixels}px`);
      visual.style.setProperty('--loom-rod-y', `${visualHeight * 0.13}px`);
      requestLoomRender();
    }

    function readLoomScroll() {
      const rect = scene.getBoundingClientRect();
      const travel = Math.max(1, rect.height - window.innerHeight);
      targetProgress = clamp(-rect.top / travel);
      requestLoomRender();
    }

    function getStates(progress) {
      return timing.map((item) => ({
        reveal: smoothstep(item.weaveStart, item.weaveEnd, progress),
        roll: smoothstep(item.rollStart, item.rollEnd, progress),
      }));
    }

    function setActiveCopy(progress) {
      const nextCopy = progress < 0.25 ? 0 : progress < 0.52 ? 1 : 2;
      if (nextCopy === activeCopy) return;
      activeCopy = nextCopy;
      copyItems.forEach((item, index) => {
        const isActive = index === activeCopy;
        item.classList.toggle('is-active', isActive);
        item.setAttribute('aria-hidden', String(!isActive));
      });
      current.textContent = String(activeCopy + 1).padStart(2, '0');
    }

    function drawCarpet(index, state, radius, gap, heightNdc, fringeNdc, exit) {
      if (!textures[index].ready || state.reveal <= 0.0001) return;
      gl.activeTexture(gl.TEXTURE0);
      gl.bindTexture(gl.TEXTURE_2D, textures[index].texture);
      gl.uniform2f(uniforms.viewport, canvas.width, canvas.height);
      gl.uniform1f(uniforms.height, heightNdc);
      gl.uniform1f(uniforms.fringe, fringeNdc);
      gl.uniform1f(uniforms.roll, state.roll);
      gl.uniform1f(uniforms.radius, radius);
      gl.uniform1f(uniforms.gap, gap);
      gl.uniform1f(uniforms.reveal, state.reveal);
      gl.uniform1f(uniforms.exit, exit);
      gl.drawElements(gl.TRIANGLES, indices.length, gl.UNSIGNED_SHORT, 0);
    }

    function updateShuttle(states, radii, hangingGap, exit) {
      let active = -1;
      for (let index = states.length - 1; index >= 0; index -= 1) {
        if (states[index].reveal > 0.001 && states[index].reveal < 0.999) {
          active = index;
          break;
        }
      }

      if (active === -1 || exit > 0.01) {
        shuttle.style.opacity = '0';
        return;
      }

      const reveal = states[active].reveal;
      const shuttleX = Math.sin(reveal * Math.PI * 24) * cardWidthPixels * 0.34;
      const carpetTopOffset = (radii[active] + hangingGap) * visualHeight * 0.5;
      const shuttleY = carpetTopOffset + (1 - reveal) * (cardHeightPixels + fringeLengthPixels) - 14;
      shuttle.style.opacity = '0.92';
      shuttle.style.transform = `translate3d(calc(-50% + ${shuttleX}px), ${shuttleY}px, 0) rotate(${Math.sin(reveal * Math.PI * 24) * 3}deg)`;
    }

    function updateExit(exit) {
      const lift = exit * visualHeight * -0.29;
      rod.style.transform = `translate3d(-50%, ${lift}px, 0)`;
      rodEnds.forEach((end) => {
        end.style.transform = `translate3d(0, ${lift}px, 0)`;
      });
      warp.style.opacity = String(1 - exit * 0.78);
      warp.style.transform = `translate3d(-50%, ${lift * 0.7}px, 0) scaleX(${1 + exit * 2.25})`;
      visual.style.setProperty('--loom-frame-lift', `${lift * 0.3}px`);
      visual.style.setProperty('--loom-frame-opacity', String(1 - smoothstep(0.18, 0.86, exit)));

      const copyExit = smoothstep(0.12, 0.82, exit);
      copyRoot.style.opacity = String(1 - copyExit);
      copyRoot.style.transform = `translate3d(0, ${copyExit * -20}px, 0)`;
    }

    function renderLoom() {
      loomFrame = 0;
      const difference = targetProgress - displayedProgress;
      displayedProgress += difference * 0.18;
      if (Math.abs(difference) < 0.0002) displayedProgress = targetProgress;

      const states = getStates(displayedProgress);
      const exit = smoothstep(0.94, 1.0, displayedProgress);
      const heightNdc = (2 * cardHeightPixels) / visualHeight;
      const fringeNdc = (2 * fringeLengthPixels) / visualHeight;
      const hangingGap = 0.08;
      const radii = [
        0.041 + 0.019 * states[0].roll,
        0.060 + 0.017 * states[1].roll,
        0.077 + 0.015 * states[2].roll,
      ];

      gl.clearColor(0, 0, 0, 0);
      gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
      gl.useProgram(program);
      gl.bindVertexArray(vertexArray);
      drawCarpet(0, states[0], radii[0], hangingGap, heightNdc, fringeNdc, exit);
      drawCarpet(1, states[1], radii[1], hangingGap, heightNdc, fringeNdc, exit);
      drawCarpet(2, states[2], radii[2], hangingGap, heightNdc, fringeNdc, exit);

      updateShuttle(states, radii, hangingGap, exit);
      updateExit(exit);
      setActiveCopy(displayedProgress);
      progressBar.style.transform = `scaleX(${displayedProgress})`;

      if (Math.abs(targetProgress - displayedProgress) >= 0.0002) requestLoomRender();
    }

    function requestLoomRender() {
      if (loomFrame) return;
      loomFrame = window.requestAnimationFrame(renderLoom);
    }

    document.documentElement.classList.add('has-loom-webgl');
    window.addEventListener('scroll', readLoomScroll, { passive: true });
    window.addEventListener('resize', resizeLoom, { passive: true });
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) requestLoomRender();
    });

    resizeLoom();
    readLoomScroll();
  } catch (error) {
    document.documentElement.classList.remove('has-loom-webgl');
    console.warn('WebGL loom fallback enabled.', error);
  }
})();

/* Форма заявки #lead: клиентская валидация + честный фолбэк, если приём не настроен.
   Никогда не сообщаем «отправлено», пока сервер не подтвердил {ok:true}. */
(() => {
  const form = document.querySelector('[data-lead-form]');
  if (!form) return;
  const status = form.querySelector('[data-lead-status]');
  const submit = form.querySelector('button[type="submit"]');

  function setStatus(msg, kind) {
    if (!status) return;
    status.textContent = msg;
    if (kind) status.dataset.kind = kind;
    else status.removeAttribute('data-kind');
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    // Родная проверка required-полей и обязательного согласия.
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    setStatus('Отправляю…', 'pending');
    if (submit) submit.disabled = true;

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(form),
      });

      let data = null;
      try { data = await res.json(); } catch (_) { /* не JSON: статик-хостинг без PHP */ }

      if (res.ok && data && data.ok) {
        form.reset();
        setStatus('Готово. Рамазан свяжется с вами в течение рабочего дня.', 'ok');
        if (typeof window.labazanGoal === 'function') window.labazanGoal('form_submit');
      } else if (data && data.error === 'consent') {
        setStatus('Отметьте согласие на обработку персональных данных.', 'error');
      } else if (data && data.error === 'validation') {
        setStatus('Проверьте контакт: телефон, email или Telegram-ник.', 'error');
      } else if (data && data.error === 'rate_limited') {
        setStatus('Слишком много попыток. Подождите минуту и попробуйте снова.', 'error');
      } else if (data && data.error) {
        setStatus('Не удалось отправить. Напишите напрямую в Telegram или позвоните.', 'error');
      } else {
        // Нет валидного JSON: на превью статик-сервер без PHP. Не врём про отправку.
        setStatus('Форма заработает после переноса на рабочий хостинг. Пока напишите напрямую в Telegram или позвоните.', 'error');
      }
    } catch (_) {
      setStatus('Сеть недоступна. Напишите напрямую в Telegram или позвоните.', 'error');
    } finally {
      if (submit) submit.disabled = false;
    }
  });
})();

/* Клик по WebGL-ковру. Оверлей-ссылка [data-loom-hit] следует за активным ковром:
   читаем класс is-active (его ставит станок в setActiveCopy) и берём href текстовой
   ссылки того же вопроса. Отрисовку станка не трогаем — только читаем состояние. */
(() => {
  const hit = document.querySelector('[data-loom-hit]');
  const items = [...document.querySelectorAll('[data-loom-copy]')];
  if (!hit || !items.length) return;

  function sync() {
    const active = items.find((el) => el.classList.contains('is-active')) || items[0];
    const link = active.querySelector('.loom-story__action');
    if (link) hit.setAttribute('href', link.getAttribute('href'));
  }

  const observer = new MutationObserver(sync);
  items.forEach((el) => observer.observe(el, { attributes: true, attributeFilter: ['class'] }));
  sync();
})();

/* Цели Яндекс.Метрики на клики и доскролл. Всё уходит через window.labazanGoal,
   которая молчит без согласия/валидного ID. Делегирование на document — работает
   и по добавленным динамически ссылкам (напр. оверлей ковра). */
(() => {
  const goal = (name) => {
    if (typeof window.labazanGoal === 'function') window.labazanGoal(name);
  };

  document.addEventListener('click', (event) => {
    const a = event.target.closest && event.target.closest('a');
    if (!a) return;
    const href = a.getAttribute('href') || '';
    if (href.indexOf('tel:') === 0) goal('click_phone');
    else if (/(^https?:)?\/\/t\.me\//.test(href) || href.indexOf('tg://') === 0) goal('click_telegram');
    else if (
      a.classList.contains('primary-button') ||
      a.hasAttribute('data-cta') ||
      a.hasAttribute('data-sticky-action')
    ) goal('click_cta');
  }, true);

  // Доскролл до блока тарифов — один раз.
  const pricing = document.getElementById('pricing');
  if (pricing && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) { goal('scroll_packages'); io.disconnect(); }
      });
    }, { threshold: 0.4 });
    io.observe(pricing);
  }
})();
