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
          data-bushes="{{ json_encode($bushes ?? []) }}"
          data-walkers="{{ json_encode($walkers ?? []) }}"
          data-now="{{ $now ?? 0 }}">
        <a class="help-link" href="/how-it-works" title="Как это работает" aria-label="Как это работает">
            <span aria-hidden="true">🎃</span><span class="help-link__mark" aria-hidden="true">?</span>
        </a>

        @foreach($elements as $element)
            <button type="button" class="pumpkin"
                    data-element="{{ $element['id'] }}"
                    data-type="{{ $element['type'] }}"
                    data-size="{{ $element['size'] ?? 'm' }}"
                    data-stage="{{ $element['stage'] }}"
                    data-clicks="{{ $element['clicks'] }}"
                    style="--x: {{ $element['x'] }}; --y: {{ $element['y'] }}; z-index: {{ (int) round($element['y'] * 10) }}"
                    aria-label="Тыква {{ $element['id'] }}"><span class="pumpkin__emoji" aria-hidden="true">🎃</span></button>
        @endforeach

        <aside class="debug" data-debug-panel hidden aria-label="Отладка">
            <button type="button" class="debug__button" data-action="reset">Заново</button>
            <button type="button" class="debug__button" data-action="click-all">Клик по всем</button>
        </aside>

        <footer class="hud">
            <span class="hud__item hud__status" data-status="disconnected">нет связи</span>
            <span class="hud__item">Раунд <b data-hud-round>{{ $round }}</b></span>
            <span class="hud__item">Сцена <b data-hud-scene>{{ $scene }}</b> <span class="hud__scene-name" data-hud-scene-name></span></span>
            <span class="hud__item">Готово <b data-hud-done>0</b> из <b data-hud-total>{{ count($elements) }}</b></span>
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
