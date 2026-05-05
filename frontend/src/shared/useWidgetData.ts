import { onMounted, onUnmounted, ref, type Ref } from 'vue';

export interface UseWidgetData<T> {
  data: Ref<T | null>;
  refetch: () => Promise<void>;
}

export function useWidgetData<T>(actionPath: string): UseWidgetData<T> {
  const data = ref<T | null>(null) as Ref<T | null>;
  let abortController: AbortController | null = null;

  const refetch = async () => {
    abortController?.abort();
    abortController = new AbortController();
    try {
      const url = window.Craft.getActionUrl(actionPath);
      const res = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal: abortController.signal,
      });
      if (res.ok) {
        data.value = (await res.json()) as T;
      }
    } catch (e) {
      if (e instanceof DOMException && e.name === 'AbortError') return;
      console.error(`Error fetching ${actionPath}`, e);
    }
  };

  onMounted(refetch);
  onUnmounted(() => abortController?.abort());

  return { data, refetch };
}
