<extends:layout.base title="Hellowin" scene="{{ $scene }}"/>

<stack:push name="scripts">
    <script src="https://cdn.jsdelivr.net/npm/centrifuge@5.7.4/dist/centrifuge.js"></script>
    <script type="module" src="/js/app.js"></script>
</stack:push>

<define:body>
    <main class="patch" id="app"
          data-centrifugo-url="{{ $centrifugoUrl }}"
          data-round="{{ $round }}"
          data-scene-stages="{{ $sceneStages }}"
          data-stage-max="{{ $stageMax ?? 4 }}"
          data-threshold="{{ $threshold }}"
          data-k="{{ $k ?? 1 }}"
          data-online="{{ $online ?? 0 }}"
          data-bushes="{{ json_encode($bushes ?? []) }}"
          data-walkers="{{ json_encode($walkers ?? []) }}"
          data-bar="{{ json_encode($bar ?? ['mugs' => 0, 'pouring' => null, 'drunk' => 0, 'target' => 0]) }}"
          data-bar-layout="{{ json_encode($barLayout ?? null) }}"
          data-now="{{ $now ?? 0 }}"
          data-started-at="{{ $startedAt ?? 0 }}"
          data-ready="{{ ($ready ?? false) ? 1 : 0 }}"
          data-finale="{{ json_encode($finale ?? null) }}"
          data-paused="{{ ($paused ?? false) ? 1 : 0 }}"
          data-zombies="{{ ($zombies ?? false) ? 1 : 0 }}">
        <div class="sound-panel" data-sound-panel role="group" aria-label="Звук" hidden>
            <button type="button" class="sound-panel__button sound-panel__button--sound" data-sound-toggle data-icon aria-pressed="true" aria-label="Звук включён" title="Звук"></button>
            <button type="button" class="sound-panel__button sound-panel__button--music" data-music-toggle aria-pressed="true" aria-label="Музыка включена" title="Музыка"></button>
            <input type="range" class="sound-panel__volume" data-volume min="0" max="100" step="5" value="100" aria-label="Громкость" title="Громкость">
        </div>
        <a class="help-link" href="/how-it-works" target="_blank" rel="noopener" title="Как это работает (в новой вкладке)" aria-label="Как это работает (в новой вкладке)">
            <span aria-hidden="true">🎃</span><span class="help-link__mark" aria-hidden="true">?</span>
        </a>

        @foreach($elements as $element)
            @if($element['type'] !== 'pumpkin')
            <!-- The bonfire, the magic circle and any later type: own threshold and last stage; an unknown type gets
                 the neutral .element class (hidden), never a pumpkin's look. -->
            <button type="button" class="{{ \in_array($element['type'], ['bonfire', 'circle'], true) ? $element['type'] : 'element' }}"
                    data-element="{{ $element['id'] }}"
                    data-type="{{ $element['type'] }}"
                    data-stage="{{ $element['stage'] }}"
                    data-clicks="{{ $element['clicks'] }}"
                    data-threshold="{{ $element['threshold'] ?? 1 }}"
                    data-stage-max="{{ $element['stageMax'] ?? 0 }}"
                    style="--x: {{ $element['x'] }}; --y: {{ $element['y'] }}; z-index: {{ (int) round($element['y'] * 10) }}"
                    aria-label="{{ ['bonfire' => 'Костёр', 'circle' => 'Магический круг'][$element['type']] ?? $element['id'] }}"><span class="element__emoji" aria-hidden="true">{{ ['bonfire' => '🔥', 'circle' => '⭕'][$element['type']] ?? '' }}</span></button>
            @else
            <button type="button" class="pumpkin"
                    data-element="{{ $element['id'] }}"
                    data-type="{{ $element['type'] }}"
                    data-size="{{ $element['size'] ?? 'm' }}"
                    data-stage="{{ $element['stage'] }}"
                    data-clicks="{{ $element['clicks'] }}"
                    @if(!empty($element['reachable'])) data-reachable @endif
                    style="--x: {{ $element['x'] }}; --y: {{ $element['y'] }}; z-index: {{ (int) round($element['y'] * 10) }}"
                    aria-label="Тыква {{ $element['id'] }}"><span class="pumpkin__emoji" aria-hidden="true">🎃</span></button>
            @endif
        @endforeach

        <!-- The bar's counter (scene 0): over the counter's art, a mug for a character with empty hands. -->
        <button type="button" class="bar-counter" data-bar-counter aria-disabled="true" hidden
                aria-label="Взять пиво" title="Взять пиво"><span class="bar-counter__emoji" aria-hidden="true">🍺</span></button>

        <aside class="debug" data-debug-panel hidden aria-label="Пульт ведущего"></aside>

        <footer class="hud">
            <span class="hud__item hud__status" data-status="disconnected">нет связи</span>
            <span class="hud__item">Раунд <b data-hud-round>{{ $round }}</b></span>
            <span class="hud__item">Сцена <b data-hud-scene>{{ $scene }}</b> <span class="hud__scene-name" data-hud-scene-name></span></span>
            <span class="hud__item hud__drinks" data-hud-drinks role="progressbar" aria-label="Выпито в баре" aria-valuemin="0" hidden><span class="hud__meter" aria-hidden="true"><span class="hud__meter-fill"></span></span><span data-hud-drinks-text></span></span>
            <span class="hud__item" data-hud-progress>Готово <b data-hud-done>0</b> из <b data-hud-total>{{ count(array_filter($elements, static fn(array $e): bool => $e['type'] === 'pumpkin')) }}</b></span>
            <span class="hud__item hud__ready" data-hud-ready hidden>сцена готова — ждём ведущего</span>
            <button type="button" class="hud__item hud__button" data-reveal-open hidden>Числа вечера</button>
            <button type="button" class="hud__item hud__button hud__button--drink" data-drink hidden>Выпить</button>
            <button type="button" class="hud__item hud__button" data-join-open>Персонаж</button>
        </footer>
    </main>

    <dialog class="join" data-join aria-labelledby="join-title">
        <form class="join__form" method="dialog">
            <h2 class="join__title" id="join-title">Кто идёт на поле?</h2>
            <div class="join__body">
                <canvas class="join__preview" data-join-preview width="48" height="48" aria-hidden="true"></canvas>
                <div class="join__fields">
                    <label class="join__label" for="join-name">Имя</label>
                    <input class="join__name" id="join-name" data-join-name name="name" maxlength="24" autocomplete="nickname"
                           autofocus spellcheck="false" aria-describedby="join-error">
                    <p class="join__error" id="join-error" data-join-error role="alert"></p>
                    <div class="join__parts">
                        <div class="join__part" data-part="body"><span class="join__part-name">Тело</span><button type="button" class="join__step" data-step="-1" aria-label="Тело: назад">‹</button><output class="join__value" data-part-value aria-live="polite"></output><button type="button" class="join__step" data-step="1" aria-label="Тело: дальше">›</button></div>
                        <div class="join__part" data-part="cloak"><span class="join__part-name">Накидка</span><button type="button" class="join__step" data-step="-1" aria-label="Накидка: назад">‹</button><output class="join__value" data-part-value aria-live="polite"></output><button type="button" class="join__step" data-step="1" aria-label="Накидка: дальше">›</button></div>
                        <div class="join__part" data-part="face"><span class="join__part-name">Лицо</span><button type="button" class="join__step" data-step="-1" aria-label="Лицо: назад">‹</button><output class="join__value" data-part-value aria-live="polite"></output><button type="button" class="join__step" data-step="1" aria-label="Лицо: дальше">›</button></div>
                        <div class="join__part" data-part="eyes"><span class="join__part-name">Глаза</span><button type="button" class="join__step" data-step="-1" aria-label="Глаза: назад">‹</button><output class="join__value join__value--swatch" data-part-value aria-live="polite"></output><button type="button" class="join__step" data-step="1" aria-label="Глаза: дальше">›</button></div>
                        <div class="join__part" data-part="hat"><span class="join__part-name">Шапка</span><button type="button" class="join__step" data-step="-1" aria-label="Шапка: назад">‹</button><output class="join__value" data-part-value aria-live="polite"></output><button type="button" class="join__step" data-step="1" aria-label="Шапка: дальше">›</button></div>
                    </div>
                    <button type="button" class="join__button join__button--quiet" data-join-random>🎲 Случайно</button>
                </div>
            </div>
            <div class="join__actions">
                <button type="button" class="join__button join__button--quiet" data-join-watch>Просто смотреть</button>
                <button type="submit" class="join__button" data-join-submit>На поле</button>
            </div>
        </form>
    </dialog>
</define:body>
