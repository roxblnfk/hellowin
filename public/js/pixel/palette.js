// Every ramp shifts hue: shadows toward red-brown / blue, highlights toward yellow.
export const PALETTE = {
    outline: 0x2a1620,

    pumpkinDeep: 0x8a2a1c,
    pumpkinDark: 0xc2481a,
    pumpkin: 0xe8791c,
    pumpkinLight: 0xf8a535,
    pumpkinShine: 0xffd860,
    // Two more skins share the deep shadow and the shine with the main ramp.
    pumpkinRedDark: 0xae3a1e,
    pumpkinRed: 0xd65a1e,
    pumpkinRedLight: 0xee8634,
    pumpkinGoldDark: 0xcc6a16,
    pumpkinGold: 0xf0981e,
    pumpkinGoldLight: 0xfcc046,

    flesh: 0xffd27a,
    fleshDark: 0xe0963a,
    cream: 0xfff1c4,
    seedShade: 0xd4b06e,
    innerDark: 0x2e1014,
    inner: 0x6a2418,

    leafDark: 0x1d4a2c,
    leaf: 0x2e7a34,
    leafLight: 0x5cac3c,
    leafShine: 0xa6d64a,

    stemDark: 0x4e3c1c,
    stem: 0x7e6c2c,
    stemLight: 0xae9c4a,

    barkDark: 0x3a2a2e,
    bark: 0x6a4c3e,
    barkLight: 0x9c7856,

    hayDark: 0xa0742e,
    hay: 0xd6aa4c,
    hayLight: 0xf2d888,

    soilDark: 0x4a2a1e,
    soil: 0x6e4028,
    soilLight: 0x96623a,

    hazeLight: 0xa9d3d6,
    haze: 0x80b0aa,
    hazeDark: 0x5c8a84,

    sky: 0x8ccff0,
    skyLight: 0xc8ecf8,
    sun: 0xffe066,
    white: 0xffffff,

    grayLight: 0xc4c4cc,
    gray: 0x84848f,

    // Flyers: mallard and hen, plus the gold of the score popups.
    mallard: 0x1e7a50,
    mallardLight: 0x46b070,
    duckBrown: 0x8c4626,
    duckBrownLight: 0xb86a3a,
    duckGray: 0xbcb8b0,
    duckGrayDark: 0x7c7872,
    speculum: 0x4058c8,
    beak: 0xf4c22e,
    comb: 0xd8342c,
    chickenShade: 0xd6ccbc,
    gold: 0xffd040,

    // Characters: skins, costume cloth and the eye glow. Cloth stays lighter than the outline so it does not merge.
    skin: 0xf4c08c,
    skinShade: 0xd08458,
    pale: 0xe0dcec,
    paleShade: 0xa8a0c4,
    zombie: 0xa8c47c,
    zombieShade: 0x6e9058,
    violetDark: 0x3e2e5c,
    violet: 0x60468c,
    violetLight: 0x8c6cbc,
    crimsonDark: 0x7e1e2e,
    crimson: 0xb42e36,
    denim: 0x4c6cb0,
    denimDark: 0x34487c,
    // The PHP elephant in the PHP logo's blue-violet (#8892bf), shading toward a deep indigo, the ears' inside paler.
    elephantLight: 0xc4c8e8,
    elephant: 0x8892bf,
    elephantDark: 0x5a6198,
    // The Go gopher: a sky-cyan fur shading toward a deep teal-blue, the beige of its muzzle, paws and feet.
    gopher: 0x6ccce2,
    gopherDark: 0x3a94b4,
    gopherTan: 0xf0d2a2,
    gopherTanShade: 0xc49a6a,
    // The tin robot: a chipped turquoise enamel, duller and greener than the gopher, shading toward a slate blue.
    robotLight: 0x92d2c8,
    robot: 0x56a4a6,
    robotDark: 0x366a7c,
    blush: 0xf49482,
    eyeAmber: 0xffa424,
    eyeGreen: 0x7cf064,
    eyeRed: 0xff3c34,
    eyeBlue: 0x50c4ff,
    eyeViolet: 0xc880ff,

    // Night flyers. Crow and bat stay a step above the outline so their shapes survive on a dark sky; the ghost is
    // pale with a blue edge, so it reads on the day sky too.
    crowDark: 0x2e2a42,
    crow: 0x4a4562,
    crowLight: 0x77729a,
    bat: 0x4e3448,
    batWing: 0x7a4e6e,
    batLight: 0xa77898,
    ghost: 0xf0f4ff,
    ghostShade: 0xbac8ea,
    ghostEdge: 0x7a8cc4,
    ecto: 0x9cf0b4,

    // The owl: a grey-brown, mid-value ramp, so it neither melts into the bark nor glares at night.
    owlDark: 0x5a4a44,
    owl: 0x8c7664,
    owlLight: 0xb8a088,
    owlFace: 0xdccaa8,
    owlEye: 0xffb62e,
    owlEyeGlow: 0xfff2a0,

    // Scenes 1–3: fire (the pumpkin ramp plus a white-hot core), smoke, hookah brass, and the magic glows — green
    // for scene 2's dark magic, violet for the blood-moon energy. Glows are light sources: lighting never dims them.
    flameCore: 0xfffbe2,
    smokeDark: 0x4a4452,
    steelDark: 0x55536a,
    copper: 0xc0682e,
    copperLight: 0xf0a65a,
    magicCore: 0xf4ffe0,
    eerieHot: 0xd8ff9c,
    eerieMid: 0x48c45a,
    eerieDark: 0x2e9a50,
    eerieDeep: 0x1a5838,
    violetHot: 0xf2d6ff,
    violetGlow: 0xa462f0,
    // The bonfire after the blast: ash and charcoal, a dying ember, and the red of the magic circle drawn on it.
    ashLight: 0x6a6266,
    ash: 0x3e383c,
    ashDark: 0x1e1a1e,
    ember: 0xe0461e,
    rune: 0xff4432,
    runeDark: 0x9a1e24,

    // Skies of the later scenes, top to horizon. Sunset runs violet → rose → orange; night is a blue-violet ramp
    // light enough at the horizon for the black hills to stand out; the blood moon turns it maroon.
    sunsetTop: 0x46346e,
    sunsetHigh: 0x84477e,
    sunsetMid: 0xcc5f76,
    sunsetLow: 0xf2945a,
    sunsetGlow: 0xffcc80,
    sunsetSun: 0xffa846,
    nightTop: 0x0a0a1e,
    nightHigh: 0x13142f,
    nightMid: 0x1c1e48,
    nightLow: 0x2a2f62,
    bloodTop: 0x12060e,
    bloodHigh: 0x260a18,
    bloodMid: 0x421222,
    bloodLow: 0x661c26,
    moonLight: 0xfbf7e4,
    moon: 0xdcd8c2,
    moonShade: 0xa6a6b6,
    bloodMoonLight: 0xff9a74,
    bloodMoon: 0xd8402c,
    bloodMoonShade: 0x8a1c20,

    // The finale. FrankenPHP in its logo colours: the lime green (franken = the logo's #b3d133), the deep purple ink
    // of its outline and flat-top hair, lavender bolts. The cursed-world glow repeats the lime as a light source; its
    // keys sit a step off the skin values, so relighting by colour never mistakes FrankenPHP's skin for a glow.
    frankenLight: 0xd4ea5c,
    franken: 0xb3d133,
    frankenDark: 0x8aa626,
    frankenDeep: 0x5c7418,
    frankenInk: 0x3a0a72,
    frankenInkLight: 0x5e2ea0,
    frankenBoltShine: 0xece4f6,
    frankenBolt: 0xc3b2d3,
    frankenBoltDark: 0x9280b2,
    frankenGlowCore: 0xf6ffd4,
    frankenGlowHot: 0xe0f47a,
    frankenGlow: 0xb2d236,
    frankenGlowDark: 0x7c9a1c,
    frankenGlowDeep: 0x48600e,
    // Smoke of the summoning, a step lighter than smokeDark; lightning white-blue; the hot core of the red column.
    smoke: 0x6c6474,
    smokeLight: 0x9c94a4,
    lightningCore: 0xfaf8ff,
    lightning: 0xc4d4ff,
    lightningEdge: 0x7c8cf0,
    columnCore: 0xffe0d0,
    // The bar (scene 0, bar.js): the mead hall's timber — a warm brown ramp for logs, tables and benches, a
    // greyer one for the worn floor planks — the hearth's stones, the iron of hoops and sconces, and the shields'
    // paint. The darkest timber stays a step above the outline, so the gaps between logs still read as gaps.
    timberDeep: 0x341c16,
    timberDark: 0x52301e,
    timber: 0x7a4a2a,
    timberLight: 0xa26a3c,
    timberShine: 0xc88e54,
    plankDeep: 0x3a2620,
    plankDark: 0x5a3c2a,
    plank: 0x7c5838,
    plankLight: 0x9e7a4e,
    stoneDeep: 0x332c34,
    stoneDark: 0x4e4650,
    stone: 0x72686e,
    stoneLight: 0x988a8c,
    iron: 0x3c3a48,
    ironLight: 0x6e6c80,
    shieldBlue: 0x2e4f86,
    shieldGreen: 0x2f6a40,
    // The pulp in a pumpkin's cut-outs before it is scooped: pale flesh, and its shade under the skin's edge.
    pulp: 0xe6a447,
    pulpShade: 0x96441c,
    // The rapier's light (finale steps 6–7): the blade, its golden lightning and the glow of whoever holds it. A light
    // source like the cursed glow, and a step off `gold` and the pumpkin ramp, so relighting by colour never takes
    // a golden button or a pumpkin's shine for it.
    goldCore: 0xfffae0,
    goldHot: 0xffe57a,
    goldGlow: 0xf2b42c,
    goldDeep: 0xb0701a,
    // The dawn of finale step 7: a violet-blue sky warming through rose and peach to a pale gold horizon, and the
    // rising sun.
    dawnTop: 0x4c4f8e,
    dawnHigh: 0x9a6aa6,
    dawnMid: 0xe68c98,
    dawnLow: 0xffb98a,
    dawnGlow: 0xffe2a4,
    dawnSun: 0xfff3c2,
    dawnSunRim: 0xffcf72,
    // The bodybuilder: a sun-tanned skin ramp, warmer and darker than `skin`, for a bare torso lit from the top left,
    // and the khaki of his army trousers.
    tanLight: 0xe6a46a,
    tan: 0xc07a46,
    tanShade: 0x86482c,
    khakiLight: 0xa8a462,
    khaki: 0x7c7c44,
    khakiDark: 0x50542e,
    // Black eyes: a step under the outline, so the eye reads as black against it; the glint carries the shape.
    eyeBlack: 0x140c12,
    // The punk mohawk's root: a hot pink that stands apart from every eye colour its tips take, and its shade.
    mohawk: 0xff3c9a,
    mohawkShade: 0xb81e6a,
};
