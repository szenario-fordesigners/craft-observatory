import { ref, computed } from 'vue';
import { format, subDays } from 'date-fns';

export type RangeValue =
  | 'today' | '24h' | 'this_week' | '7d'
  | 'this_month' | '30d' | '90d'
  | 'this_year' | '6m' | '12m' | 'custom';

export interface CustomDateRange {
  startDate: string;
  endDate: string;
}

/**
 * The presets offered by the date picker, in dropdown order.
 *
 * Labels only: the window each preset denotes is resolved server-side, in the site's timezone.
 * Computing the boundaries here from `new Date()` tied every preset to the viewer's own clock,
 * so a CP session in another timezone asked for a shifted window and read the wrong day keys
 * back out of the mirror. The names below must match the presets AnalyticsTime::presetRange()
 * knows about.
 */
export const PRESET_RANGES: { value: RangeValue; label: string }[] = [
  { value: 'today', label: 'Today' },
  { value: '24h', label: 'Last 24 hours' },
  { value: 'this_week', label: 'This week' },
  { value: '7d', label: 'Last 7 days' },
  { value: 'this_month', label: 'This month' },
  { value: '30d', label: 'Last 30 days' },
  { value: '90d', label: 'Last 90 days' },
  { value: 'this_year', label: 'This year' },
  { value: '6m', label: 'Last 6 months' },
  { value: '12m', label: 'Last 12 months' },
];

export function useDateRange(initialRange: RangeValue = '24h') {
  const currentRangeValue = ref<RangeValue>(initialRange);

  // Prefills the custom date inputs only. Browser-local is fine for that: the user sees the
  // exact dates that will be sent and confirms them, so there is no hidden shift.
  const customRange = ref<CustomDateRange>({
    startDate: format(subDays(new Date(), 6), 'yyyy-MM-dd'),
    endDate: format(new Date(), 'yyyy-MM-dd'),
  });

  /** Query params naming the window for the backend to resolve. */
  const rangeParams = computed<Record<string, string>>(() => {
    const params: Record<string, string> = { range: currentRangeValue.value };

    if (currentRangeValue.value === 'custom') {
      params.startDate = customRange.value.startDate;
      params.endDate = customRange.value.endDate;
    }

    return params;
  });

  const setCustomRange = (range: CustomDateRange) => {
    customRange.value = range;
    currentRangeValue.value = 'custom';
  };

  return {
    currentRangeValue,
    customRange,
    rangeParams,
    setCustomRange,
  };
}
