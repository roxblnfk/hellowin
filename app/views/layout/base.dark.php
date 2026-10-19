<!DOCTYPE html>
<html lang="@{locale|en}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>${title|Hellowin}</title>
    <link rel="stylesheet" href="/css/app.css">
    <stack:collect name="styles" level="2"/>
</head>
<body data-stage="${stage|0}">
<block:body/>
<stack:collect name="scripts" level="2"/>
</body>
<hidden>${context}</hidden>
</html>
