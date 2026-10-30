// The players' abilities (docs/spec/frontend.md, «Способности»): what an `ability` or `power` event means for the
// page, what Space does, and the state of the HUD's button. No PixiJS and no DOM: the Node tests load it.

/** The abilities of protocol.md, «Способности»; anything else is none. */
export const ABILITIES = ['flyers', 'fart', 'meep', 'lightning', 'pumpkin'];
/** An `ability` or `power` event older than this by its `at` (server clock) draws and sounds nothing. */
export const ABILITY_STALE_MS = 1500;
/**
 * A browser asks for its ability no closer than this: a mashed Space or button sends one RPC, not a burst of `busy`.
 * The server's cooldowns are longer; its `readyAt` keeps the button off until then.
 */
export const ABILITY_GAP_MS = 500;

/** An ability as the model keeps it: one of ABILITIES, or null. */
export const abilityOf = (value) => (ABILITIES.includes(value) ? value : null);

/**
 * An `ability` event: { id, ability (null — the moderator took it away), at, fresh } — `fresh`: not older than
 * ABILITY_STALE_MS at `serverNow`, so its pillar and chime still play; null for an event without a player.
 */
export function grantOf(event, serverNow) {
    const id = event?.player?.id;
    if (!id) return null;
    const at = event.at > 0 ? event.at : serverNow;
    return { id, ability: abilityOf(event.ability), at, fresh: serverNow - at <= ABILITY_STALE_MS };
}

/**
 * A `power` event: { id, ability, at, fresh } with the lightning's `flyer` and `hit` and the pumpkin's `n` when it
 * has them; `fresh` — not older than ABILITY_STALE_MS at `serverNow`, so it is still drawn and heard (a pumpkin is
 * state: a late one is still put on). Null when broken.
 */
export function powerOf(event, serverNow) {
    const id = event?.player?.id;
    const ability = abilityOf(event?.ability);
    if (!id || !ability) return null;
    const at = event.at > 0 ? event.at : serverNow;
    return {
        id,
        ability,
        at,
        fresh: serverNow - at <= ABILITY_STALE_MS,
        ...(typeof event.flyer === 'string' ? { flyer: event.flyer, hit: event.hit === true } : {}),
        ...(ability === 'pumpkin' ? { n: Number.isInteger(event.n) && event.n >= 0 ? event.n : 0 } : {}),
    };
}

/**
 * What a plain Space does (scenario.md, «Способности»): the dice of finale step 1 first, then the drink, then my
 * ability; null — nothing. `dice` and `drink` say whether Space belongs to them at all, not whether they would go out
 * right now: a Space while my dice tumble or my mug is busy does nothing rather than fire the ability.
 */
export function spaceAction({ dice = false, drink = false, ability = false }) {
    if (dice) return 'roll';
    if (drink) return 'drink';
    return ability ? 'ability' : null;
}

/**
 * The HUD's button: `hidden` without my walker on the field or without an ability; `disabled` while its cooldown runs
 * (`readyAt` of the last reply, server clock — for that ability only), while my RPC is unanswered, for `flyers` where
 * the set has none (`burst: false` of `limits` — the sunset), and for `lightning` with no flyer on screen to strike
 * (`target`).
 */
export function abilityButton({ ability, onField, readyAt = 0, readyFor = null, now, burst = true, pending = false, target = true }) {
    if (!onField || !ability) return { hidden: true, disabled: true };
    const cooling = readyFor === ability && now < readyAt;
    return { hidden: false, disabled: pending || cooling || (ability === 'flyers' && !burst) || (ability === 'lightning' && !target) };
}
