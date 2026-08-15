/**
 * Durations written the way a person would say them.
 *
 * An estimate is a claim about the future, so its PRECISION is a claim too:
 * "4 hours 17 minutes" says the app knows the seventeen, which it does not.
 * Rounding gets coarser as the number gets bigger, so the wording never promises
 * more than the arithmetic behind it can support.
 */
export function formatDuration(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds <= 0) return "less than a minute";

  const minutes = seconds / 60;

  if (minutes < 1) return "less than a minute";
  if (minutes < 10) return `${Math.round(minutes)} min`;

  // Under an hour, five-minute steps. Nobody plans their afternoon around 37
  // versus 39 minutes.
  if (minutes < 60) return `${Math.round(minutes / 5) * 5} min`;

  const hours = minutes / 60;

  if (hours < 10) {
    const whole = Math.floor(hours);
    // Quarter-hours, because "2 hours 15 minutes" is a real unit of planning
    // and "2 hours 13 minutes" is false precision dressed up as care.
    const rest = Math.round((hours - whole) * 4) * 15;

    if (rest === 60) return `${whole + 1} hr`;

    return rest === 0 ? `${whole} hr` : `${whole} hr ${rest} min`;
  }

  if (hours < 36) return `about ${Math.round(hours)} hours`;

  return `about ${Math.round(hours / 24)} days`;
}

/**
 * The wording for an estimate, given how it was arrived at.
 *
 * A modelled number and a measured one are different kinds of claim and are not
 * allowed to look alike here — the merchant decides whether to sit and watch
 * based on which one they are reading.
 */
export function estimateLabel(seconds: number, basis: "plan" | "progress"): string {
  const duration = formatDuration(seconds);

  return basis === "progress" ? `About ${duration} left` : `Roughly ${duration} left`;
}
