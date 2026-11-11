import { ref } from 'vue';

/**
 * Envoie un formulaire en POST et télécharge la réponse comme fichier.
 *
 * Inertia ne sait pas gérer une réponse binaire (il l'afficherait dans une fenêtre
 * d'erreur) : on passe par `fetch`, avec le jeton CSRF du cookie XSRF-TOKEN.
 * Les erreurs de validation (422) sont exposées dans `errors`, champ par champ.
 */
const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

const filenameFrom = (header: string | null, fallback: string): string => {
    const match = header?.match(/filename="?([^";]+)"?/i);

    return match ? match[1] : fallback;
};

export function usePostDownload() {
    const processing = ref(false);
    const errors = ref<Record<string, string>>({});
    const message = ref('');

    const submit = async (
        url: string,
        data: Record<string, unknown>,
        fallbackName: string,
    ): Promise<boolean> => {
        processing.value = true;
        errors.value = {};
        message.value = '';

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify(data),
            });

            if (response.status === 422) {
                const json = await response.json();
                errors.value = Object.fromEntries(
                    Object.entries(json.errors ?? {}).map(([field, list]) => [
                        field,
                        (list as string[])[0],
                    ]),
                );

                return false;
            }

            if (response.status === 429) {
                message.value = 'Too many attempts. Please wait a minute.';

                return false;
            }

            if (!response.ok) {
                message.value = `The export failed (error ${response.status}).`;

                return false;
            }

            const blob = await response.blob();
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filenameFrom(
                response.headers.get('Content-Disposition'),
                fallbackName,
            );
            link.click();
            URL.revokeObjectURL(link.href);

            return true;
        } catch {
            message.value = 'Network error. Please try again.';

            return false;
        } finally {
            processing.value = false;
        }
    };

    return { processing, errors, message, submit };
}
