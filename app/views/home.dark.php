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
          data-threshold="{{ $threshold }}">
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

        <footer class="hud">
            <span class="hud__item hud__status" data-status="disconnected">нет связи</span>
            <span class="hud__item">Раунд <b data-hud-round>{{ $round }}</b></span>
            <span class="hud__item">Сцена <b data-hud-scene>{{ $scene }}</b> <span class="hud__scene-name" data-hud-scene-name></span></span>
            <span class="hud__item">Готово <b data-hud-done>0</b> из <b data-hud-total>{{ count($elements) }}</b></span>
        </footer>
    </main>
</define:body>
