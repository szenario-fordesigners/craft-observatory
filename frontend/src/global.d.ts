export {};

declare global {
  interface Window {
    Craft: {
      getActionUrl: (path: string) => string;
      csrfTokenValue: string;
      csrfTokenName: string;
    }
  }
}
