<!DOCTYPE html>
<html lang="@{locale|ru}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>${title|Hellowin}</title>
    @if(isset($origin))
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $origin }}/">
    <meta property="og:title" content="[[RoadRunner мёртв?]]">
    <meta property="og:description" content="[[Хэллоуинский ритуал в пиксель-арте: воскресим RoadRunner вместе, в реальном времени.]]">
    <meta property="og:image" content="{{ $origin }}/og.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    @endif
    <link rel="stylesheet" href="/css/app.css">
    <stack:collect name="styles" level="2"/>
</head>
<body data-scene="${scene|0}">
<block:body/>
<stack:collect name="scripts" level="2"/>
</body>
<hidden>${context}</hidden>
</html>
