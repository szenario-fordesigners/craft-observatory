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

  const refetch = async () => {
    abortController?.abort();
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
      loading.value = false;
    }
  };

  onMounted(refetch);
  onUnmounted(() => abortController?.abort());

  return { data, loading, error, refetch };
}
