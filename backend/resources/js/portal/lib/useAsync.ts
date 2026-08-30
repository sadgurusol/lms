import { useEffect, useState } from 'react';

type State<T> = { loading: boolean; data?: T; error?: string };

/** Run an async loader on mount / when deps change, tracking loading & error. */
export function useAsync<T>(fn: () => Promise<T>, deps: unknown[]): State<T> {
    const [state, setState] = useState<State<T>>({ loading: true });

    useEffect(() => {
        let alive = true;
        setState({ loading: true });
        fn()
            .then((data) => alive && setState({ loading: false, data }))
            .catch((e) => alive && setState({ loading: false, error: (e as Error).message }));
        return () => {
            alive = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);

    return state;
}
