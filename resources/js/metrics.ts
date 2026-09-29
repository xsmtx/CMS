/**
 * How a telemetry reading is written down.
 *
 * One copy, for the reason `status.ts` is one copy: two screens that format
 * the same reading differently are two screens an operator has to reconcile,
 * and the first thing they will do is doubt both. The Telemetry screen and
 * the noisy-neighbour comparison print the same numbers about the same nodes.
 *
 * The unit is the server's own (`MetricUnit`), and `unitLabel` is its
 * translated word — a bare count carries whatever noun the language file
 * gives it, and a ratio, a byte count and a bit rate are scaled here because
 * "1932735283 bit/s" is a figure nobody reads.
 */
export function formatReading(value: number, unit: string | null, unitLabel = ''): string {
  if (unit === 'ratio') return `${(value * 100).toFixed(1)}%`
  if (unit === 'bytes') return scaled(value, ['B', 'kB', 'MB', 'GB', 'TB'])
  if (unit === 'bits_per_second') {
    return scaled(value, ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s'])
  }

  // Below a hundred the fraction is the information; above it, it is noise.
  const rounded = Math.abs(value) >= 100 ? Math.round(value) : value

  return `${rounded}${unitLabel === '' ? '' : ' ' + unitLabel}`
}

function scaled(value: number, units: string[]): string {
  let index = 0
  let current = value

  while (current >= 1000 && index < units.length - 1) {
    current /= 1000
    index++
  }

  return `${current.toFixed(index === 0 ? 0 : 1)} ${units[index]}`
}
