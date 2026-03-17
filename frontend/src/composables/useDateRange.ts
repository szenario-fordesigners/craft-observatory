import { ref, computed } from 'vue';
import {
  startOfDay, endOfDay,
  subHours, subDays, subMonths,
  startOfWeek, endOfWeek,
  startOfMonth, endOfMonth,
  startOfYear, endOfYear,
  subYears
} from 'date-fns';

export type RangeValue = 
  | 'today' | '24h' | 'this_week' | '7d' 
  | 'this_month' | '30d' | '90d' 
  | 'this_year' | '6m' | '12m' | 'all';

export interface DateRange {
  label: string;
  value: RangeValue;
  startAt: number;
  endAt: number;
  unit: 'hour' | 'day' | 'month' | 'year';
}

export function useDateRange(initialRange: RangeValue = '24h') {
  const currentRangeValue = ref<RangeValue>(initialRange);

  const ranges: Record<RangeValue, Omit<DateRange, 'value'>> = {
    'today': {
      label: 'Today',
      startAt: startOfDay(new Date()).getTime(),
      endAt: endOfDay(new Date()).getTime(),
      unit: 'hour'
    },
    '24h': {
      label: 'Last 24 hours',
      startAt: startOfDay(subDays(new Date(), 1)).getTime(), // Often Last 24h acts as Yesterday + Today in date pickers, or explicit 24h
      endAt: endOfDay(new Date()).getTime(),
      unit: 'hour'
    },
    'this_week': {
      label: 'This week',
      startAt: startOfWeek(new Date(), { weekStartsOn: 1 }).getTime(),
      endAt: endOfWeek(new Date(), { weekStartsOn: 1 }).getTime(),
      unit: 'day'
    },
    '7d': {
      label: 'Last 7 days',
      startAt: startOfDay(subDays(new Date(), 6)).getTime(),
      endAt: endOfDay(new Date()).getTime(),
      unit: 'day'
    },
    'this_month': {
      label: 'This month',
      startAt: startOfMonth(new Date()).getTime(),
      endAt: endOfMonth(new Date()).getTime(),
      unit: 'day'
    },
    '30d': {
      label: 'Last 30 days',
      startAt: startOfDay(subDays(new Date(), 29)).getTime(),
      endAt: endOfDay(new Date()).getTime(),
      unit: 'day'
    },
    '90d': {
      label: 'Last 90 days',
      startAt: startOfDay(subDays(new Date(), 89)).getTime(),
      endAt: endOfDay(new Date()).getTime(),
      unit: 'day'
    },
    'this_year': {
      label: 'This year',
      startAt: startOfYear(new Date()).getTime(),
      endAt: endOfDay(new Date()).getTime(),
      unit: 'month'
    },
    '6m': {
      label: 'Last 6 months',
      startAt: startOfMonth(subMonths(new Date(), 5)).getTime(),
      endAt: endOfDay(new Date()).getTime(),
      unit: 'month'
    },
    '12m': {
      label: 'Last 12 months',
      startAt: startOfMonth(subMonths(new Date(), 11)).getTime(),
      endAt: endOfDay(new Date()).getTime(),
      unit: 'month'
    },
    'all': {
      label: 'All time',
      startAt: 0, 
      endAt: endOfDay(new Date()).getTime(),
      unit: 'month'
    },
  };

  const availableRanges = computed(() => {
    return Object.entries(ranges).map(([key, data]) => ({
      value: key as RangeValue,
      ...data
    }));
  });

  const currentRange = computed<DateRange>(() => {
    return {
      value: currentRangeValue.value,
      ...ranges[currentRangeValue.value]
    };
  });

  const setRange = (val: RangeValue) => {
    currentRangeValue.value = val;
  };

  return {
    currentRangeValue,
    currentRange,
    availableRanges,
    setRange
  };
}
