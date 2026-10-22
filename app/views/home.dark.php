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
          data-zombies="{{ ($zombies ?? false) ? 1 : 0 }}"
          data-cursed="{{ json_encode($cursed ?? null) }}"
          data-boss="{{ json_encode($boss ?? null) }}"
          data-dice="{{ json_encode($dice ?? null) }}"
          data-dawn="{{ json_encode($dawn ?? null) }}"
          data-flyers="{{ json_encode($flyers ?? []) }}"
          data-chat-max="{{ $chatMax ?? 120 }}">
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
        <!-- RoadRunner behind the counter after the dawn: over him, a clink for a character with beer in hand. -->
        <button type="button" class="roadrunner" data-roadrunner aria-disabled="true" hidden
                aria-label="Чокнуться с RoadRunner" title="Чокнуться с RoadRunner"><span class="roadrunner__emoji" aria-hidden="true">🐦</span></button>

        <!-- The rapier (docs/spec/frontend.md, «Рапира»): over FrankenPHP his health and the button a rapier strikes; at the
             top the magic word, or the line of a zombie worker. -->
        <button type="button" class="franken" data-franken aria-disabled="true" hidden aria-label="FrankenPHP: ударить рапирой" title="Ударить рапирой"><span class="franken__emoji" aria-hidden="true">🧟</span></button>
        <div class="boss-hp" data-boss-hp role="progressbar" aria-label="Здоровье FrankenPHP" aria-valuemin="0" hidden><span class="boss-hp__bar" aria-hidden="true"><span class="boss-hp__fill"></span></span><span class="boss-hp__text" data-boss-hp-text></span></div>
        <form class="word" data-word hidden>
            <label class="word__label" for="word-input">Молви слово</label>
            <input class="word__input" id="word-input" data-word-input name="word" maxlength="40" autocomplete="off" spellcheck="false">
            <button type="submit" class="word__button" data-word-submit>Молвить</button>
            <p class="word__note" data-word-note role="alert"></p>
        </form>
        <p class="word-zombie" data-word-zombie role="status" hidden>Ты зомби-воркер. Жди, пока расколдуют</p>

        <!-- The dice of finale step 1 (docs/spec/frontend.md, «Кости»): the call or the winner, my roll drawn big, «Бросить». -->
        <section class="dice" data-dice hidden aria-label="Кости">
            <p class="dice__note" data-dice-note role="status"></p>
            <div class="dice__faces" data-dice-faces hidden><span class="dice__face" data-dice-face></span><span class="dice__face" data-dice-face></span><span class="dice__face" data-dice-face></span></div>
            <button type="button" class="dice__roll" data-dice-roll aria-disabled="true">Бросить</button>
        </section>
        <!-- The dawn's top shooters (docs/spec/frontend.md, «Топ рассвета»): at the side on a wide screen, a folded strip
             over the HUD on a phone. -->
        <section class="dawn-top" data-dawn-top hidden aria-labelledby="dawn-top-title">
            <h2 class="dawn-top__title" id="dawn-top-title">Лучшие стрелки вечера</h2>
            <button type="button" class="dawn-top__toggle" data-dawn-toggle aria-expanded="true" aria-controls="dawn-top-list"><span data-dawn-strip></span></button>
            <ol class="dawn-top__list" id="dawn-top-list" data-dawn-list></ol>
            <p class="dawn-top__empty" data-dawn-empty>Сбивайте уток рапирой!</p>
            <p class="dawn-top__row dawn-top__row--mine" data-dawn-mine hidden></p>
        </section>
        <!-- The moderator's hit zone over RoadRunner of the finale: a click makes him meep. -->
        <button type="button" class="rr-meep" data-rr-meep hidden aria-label="RoadRunner: мип-мип" title="Мип-мип"></button>
        <aside class="debug" data-debug-panel hidden aria-label="Пульт ведущего"></aside>
        <!-- The chat (docs/spec/frontend.md, «Чат»): over the HUD, opened by «Чат» or Enter; the line becomes a bubble. -->
        <form class="chat" data-chat hidden>
            <label class="chat__label" for="chat-input">Сказать всем</label>
            <input class="chat__input" id="chat-input" data-chat-input name="text" maxlength="{{ $chatMax ?? 120 }}" autocomplete="off" spellcheck="false" enterkeyhint="send">
            <button type="submit" class="chat__button" data-chat-submit>Сказать</button>
            <p class="chat__note" data-chat-note role="alert"></p>
        </form>

        <footer class="hud">
            <span class="hud__item hud__status" data-status="disconnected">нет связи</span>
            <span class="hud__item">Раунд <b data-hud-round>{{ $round }}</b></span>
            <span class="hud__item">Сцена <b data-hud-scene>{{ $scene }}</b> <span class="hud__scene-name" data-hud-scene-name></span></span>
            <span class="hud__item hud__drinks" data-hud-drinks role="progressbar" aria-label="Выпито в баре" aria-valuemin="0" hidden><span class="hud__meter" aria-hidden="true"><span class="hud__meter-fill"></span></span><span data-hud-drinks-text></span></span>
            <span class="hud__item hud__drinks hud__goal" data-hud-goal="drinks" role="progressbar" aria-label="Выпито в проклятом мире" aria-valuemin="0" hidden><span class="hud__meter" aria-hidden="true"><span class="hud__meter-fill"></span></span><span data-hud-goal-text></span></span>
            <span class="hud__item hud__drinks hud__goal hud__goal--zombies" data-hud-goal="zombies" role="progressbar" aria-label="Упокоено зомби" aria-valuemin="0" hidden><span class="hud__skull" aria-hidden="true"></span><span class="hud__meter" aria-hidden="true"><span class="hud__meter-fill"></span></span><span data-hud-goal-text></span></span>
            <span class="hud__item" data-hud-progress>Готово <b data-hud-done>0</b> из <b data-hud-total>{{ count(array_filter($elements, static fn(array $e): bool => $e['type'] === 'pumpkin')) }}</b></span>
            <span class="hud__item hud__ready" data-hud-ready hidden>сцена готова — ждём ведущего</span>
            <button type="button" class="hud__item hud__button" data-reveal-open hidden>Числа вечера</button>
            <button type="button" class="hud__item hud__button hud__button--drink" data-drink hidden>Выпить</button>
            <button type="button" class="hud__item hud__button" data-chat-open aria-expanded="false" hidden>Чат</button>
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
