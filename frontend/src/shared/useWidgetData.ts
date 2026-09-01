import { onMounted, onUnmounted, ref, type Ref } from 'vue';

export interface UseWidgetData<T> {
  data: Ref<T | null>;
  loading: Ref<boolean>;
  error: Ref<Error | null>;
  refetch: () => Promise<void>;
}

export function useWidgetData<T>(
  actionPath: string,
  params?: Record<string, string | number>,
): UseWidgetData<T> {
  const data = ref<T | null>(null) as Ref<T | null>;
  const loading = ref(false);
  const error = ref<Error | null>(null) as Ref<Error | null>;
  let abortController: AbortController | null = null;
  let inFlight = false;

  /**
   * Fetches once, dropping the call if a previous one is still running.
   *
   * Every caller is either the initial mount or a poll tick, and the URL is fixed for the
   * lifetime of the composable — there is never a newer request that should replace an older
   * one. So an overlapping tick is dropped rather than raced.
   *
   * This used to abort the in-flight request and start a replacement. Any request slower than
   * its poll interval was then aborted by the very next tick, indefinitely: nothing ever
   * completed, so `data` never arrived, the `_syncing` flag the widgets gate their 5s poll on
   * never cleared, and the loop kept itself alive while re-running the expensive query every
   * 5s. Aborting also ran the dead request's `finally`, clearing `loading` while its
   * replacement was still in flight.
   */
  const refetch = async () => {
    if (inFlight) return;

    inFlight = true;
    abortController = new AbortController();
    loading.value = true;
    error.value = null;
    try {
      const base = window.Craft.getActionUrl(actionPath);
      let url = base;
      if (params && Object.keys(params).length > 0) {
        const sep = base.includes('?') ? '&' : '?';
        const qs = Object.entries(params)
          .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
          .join('&');
        url = `${base}${sep}${qs}`;
      }
      const res = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal: abortController.signal,
      });
      if (res.ok) {
        data.value = (await res.json()) as T;
      } else {
        error.value = new Error(`HTTP error: ${res.status}`);
      }
    } catch (e) {
      if (e instanceof DOMException && e.name === 'AbortError') return;
      console.error(`Error fetching ${actionPath}`, e);
      error.value = e instanceof Error ? e : new Error(String(e));
    } finally {
      inFlight = false;
      loading.value = false;
    }
  };

  onMounted(refetch);
  // Teardown is the only thing the controller is for now: it stops a request that is still in
  // flight when Craft removes the widget from settling into a torn-down component.
  onUnmounted(() => abortController?.abort());

  return { data, loading, error, refetch };
}
