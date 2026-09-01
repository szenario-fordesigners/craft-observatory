export function resolveCountryName(
  code?: string | null,
  locale = 'en',
  missingFallback = '—',
  unknownLabel = 'Unknown',
) {
  if (code === unknownLabel) return unknownLabel;
  if (!code) return missingFallback;
  try {
    const regionNames = new Intl.DisplayNames([locale], { type: 'region' });
    return regionNames.of(code) || code;
  } catch {
    return code;
  }
}

export default resolveCountryName;
