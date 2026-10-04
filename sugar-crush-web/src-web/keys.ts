import type { InjectionKey } from 'vue'

/** The one-time code main.ts took from the sign-in URL's fragment, if any. */
export const SIGN_IN_CODE: InjectionKey<string | null> = Symbol('sign-in-code')
