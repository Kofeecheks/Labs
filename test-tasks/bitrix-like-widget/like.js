document.addEventListener('DOMContentLoaded', () => {
    const buttons = document.querySelectorAll('.like-btn');

    buttons.forEach((button) => {
        let inFlight = false;

        button.addEventListener('click', async () => {
            if (inFlight) {
                return;
            }

            const elementId = Number.parseInt(button.dataset.elementId ?? '', 10);
            const countNode = button.querySelector('.like-count');

            if (!Number.isInteger(elementId) || elementId <= 0 || !countNode) {
                console.error('Like widget is configured incorrectly');
                return;
            }

            const currentlyLiked = button.classList.contains('is-liked');
            const action = currentlyLiked ? 'unlike' : 'like';

            inFlight = true;
            button.disabled = true;
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');

            try {
                const body = new URLSearchParams({
                    id: String(elementId),
                    action,
                    sessid: window.BX?.bitrix_sessid?.() ?? '',
                });

                const response = await fetch('/ajax/like-handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body,
                });

                let payload;

                try {
                    payload = await response.json();
                } catch {
                    throw new Error('Server returned invalid JSON');
                }

                if (!response.ok || !payload.success) {
                    throw new Error(payload.error || `HTTP ${response.status}`);
                }

                const nextCount = Number(payload.count);

                if (!Number.isFinite(nextCount) || nextCount < 0) {
                    throw new Error('Server returned invalid like count');
                }

                countNode.textContent = String(nextCount);
                button.classList.toggle('is-liked', Boolean(payload.liked));
                button.setAttribute('aria-pressed', payload.liked ? 'true' : 'false');
            } catch (error) {
                console.error('Failed to update like:', error);

                const message = button.querySelector('.like-error');
                if (message) {
                    message.textContent = 'Не удалось обновить лайк. Попробуйте еще раз.';
                    message.hidden = false;

                    window.setTimeout(() => {
                        message.hidden = true;
                    }, 3000);
                }
            } finally {
                inFlight = false;
                button.disabled = false;
                button.classList.remove('is-loading');
                button.removeAttribute('aria-busy');
            }
        });
    });
});
