(() => {
    const widget = document.querySelector('[data-client-assistant]');
    if (!widget) {
        return;
    }

    const panel = widget.querySelector('[data-assistant-panel]');
    const toggle = widget.querySelector('[data-assistant-toggle]');
    const close = widget.querySelector('[data-assistant-close]');
    const welcomeCard = widget.querySelector('[data-assistant-welcome-card]');
    const welcomeClose = widget.querySelector('[data-assistant-welcome-close]');
    const messages = widget.querySelector('[data-assistant-messages]');
    const form = widget.querySelector('[data-assistant-form]');
    const input = form.querySelector('textarea');
    const submit = form.querySelector('[data-assistant-submit]');
    const storageKey = `pilot-ai-assistant-messages-${widget.dataset.clientId}`;
    const welcomeStorageKey = 'pilotAiAssistantWelcomeDismissed';
    const memoryMessages = [];
    const maxHistoryMessages = 6;

    const persistHistory = () => {
        if (memoryMessages.length > maxHistoryMessages) {
            memoryMessages.splice(0, memoryMessages.length - maxHistoryMessages);
        }
        save(memoryMessages);
    };

    const save = (items) => {
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(items));
        } catch (error) {
            console.warn('Assistant conversation could not be saved for this browser session.', error);
        }
    };

    const normalizeHistoryItems = (items) => items.reduce((normalized, item) => {
        if (!item || !['user', 'assistant'].includes(item.role) || typeof item.content !== 'string') {
            return normalized;
        }

        const content = item.content.trim();
        if (!content) {
            return normalized;
        }

        const previous = normalized.at(-1);
        if (previous && previous.role === item.role && previous.content === content) {
            return normalized;
        }

        normalized.push({ role: item.role, content });

        return normalized;
    }, []).slice(-maxHistoryMessages);

    const pushMemoryMessage = (message) => {
        const content = typeof message.content === 'string' ? message.content.trim() : '';
        if (!['user', 'assistant'].includes(message.role) || !content) {
            return false;
        }

        const previous = memoryMessages.at(-1);
        if (previous && previous.role === message.role && previous.content === content) {
            return false;
        }

        memoryMessages.push({ role: message.role, content });

        return true;
    };

    const load = () => {
        try {
            const stored = window.sessionStorage.getItem(storageKey);
            if (!stored) {
                return [];
            }
            const parsed = JSON.parse(stored);
            if (Array.isArray(parsed)) {
                const upgradedItems = parsed
                    .map((item) => {
                        if (!item || typeof item.content !== 'string') {
                            return null;
                        }

                        if (['user', 'assistant'].includes(item.role)) {
                            return { role: item.role, content: item.content };
                        }

                        if ('CLIENT' === item.author) {
                            return { role: 'user', content: item.content };
                        }

                        if ('BOT' === item.author) {
                            return { role: 'assistant', content: item.content };
                        }

                        return null;
                    })
                    .filter((item) => item && item.content.trim().length > 0);
                return normalizeHistoryItems(upgradedItems);
            }

            return [];
        } catch (error) {
            console.warn('Assistant conversation could not be restored from this browser session.', error);
            return [];
        }
    };

    const appendMessage = (author, content, className = '') => {
        const article = document.createElement('article');
        article.className = `client-assistant__message client-assistant__message--${author.toLowerCase()} ${className}`.trim();
        const label = document.createElement('strong');
        label.textContent = author === 'CLIENT' ? 'Vous' : 'Pilot AI';
        const body = document.createElement('p');
        body.textContent = content;
        article.append(label, body);
        messages.append(article);
        messages.scrollTop = messages.scrollHeight;
        return article;
    };

    const appendDraftButton = (messageArticle, question, answer, history) => {
        if (!widget.dataset.draftEndpoint || messageArticle.querySelector('[data-assistant-draft]')) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'client-assistant__draft-action';
        button.dataset.assistantDraft = 'true';
        button.textContent = 'Créer une demande';
        button.addEventListener('click', async () => {
            if (button.disabled) {
                return;
            }

            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = 'Préparation…';

            try {
                const response = await fetch(widget.dataset.draftEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': widget.dataset.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ question, answer, history }),
                    credentials: 'same-origin',
                });

                if (response.redirected && response.url) {
                    window.location.assign(response.url);
                    return;
                }

                const result = await response.json().catch(() => null);
                if (response.ok && result && result.redirectUrl) {
                    window.location.assign(result.redirectUrl);
                    return;
                }

                throw new Error('Assistant draft request failed.');
            } catch {
                button.disabled = false;
                button.textContent = originalText;
                appendMessage('BOT', 'Impossible de préparer la demande pour le moment.', 'client-assistant__message--error');
            }
        });

        messageArticle.append(button);
    };

    const toVisualAuthor = (role) => role === 'user' ? 'CLIENT' : 'BOT';

    const buildShortHistory = () => memoryMessages
        .filter((item) => item && ['user', 'assistant'].includes(item.role) && typeof item.content === 'string')
        .slice(-maxHistoryMessages)
        .map((item) => ({
            role: item.role,
            content: item.content.trim(),
        }))
        .filter((item) => item.content.length > 0);

    const history = load();
    memoryMessages.push(...history);
    if (history.length) {
        messages.replaceChildren();
        history.forEach((item) => appendMessage(toVisualAuthor(item.role), item.content));
    }

    const isWelcomeDismissed = () => {
        try {
            return window.sessionStorage.getItem(welcomeStorageKey) === '1';
        } catch {
            return false;
        }
    };

    const dismissWelcome = () => {
        if (welcomeCard) {
            welcomeCard.hidden = true;
        }

        try {
            window.sessionStorage.setItem(welcomeStorageKey, '1');
        } catch {
            // Session storage is a convenience only; the assistant must remain usable without it.
        }
    };

    if (welcomeCard && isWelcomeDismissed()) {
        welcomeCard.hidden = true;
    }

    const setOpen = (open) => {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Fermer l’assistant Pilot AI' : 'Ouvrir l’assistant Pilot AI');
        if (open) {
            dismissWelcome();
            input.focus();
            messages.scrollTop = messages.scrollHeight;
        }
    };

    toggle.addEventListener('click', () => setOpen(panel.hidden));
    close.addEventListener('click', () => setOpen(false));
    welcomeClose?.addEventListener('click', dismissWelcome);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const content = input.value.trim();
        if (!content || content.length > 2_000 || submit.disabled) {
            return;
        }

        const shortHistory = buildShortHistory();
        const userMessage = { role: 'user', content };
        const userMessageAdded = pushMemoryMessage(userMessage);
        persistHistory();
        if (userMessageAdded) {
            appendMessage(toVisualAuthor(userMessage.role), userMessage.content);
        }
        input.value = '';
        submit.disabled = true;
        const waiting = appendMessage('BOT', 'Pilot AI réfléchit…');

        const payload = { message: content, history: shortHistory };
        if (widget.dataset.ticketId) {
            payload.ticketId = Number(widget.dataset.ticketId);
        }

        try {
            const response = await fetch(widget.dataset.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': widget.dataset.csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
            });
            const result = await response.json();
            waiting.remove();

            if (!response.ok || result.success !== true || !result.message || typeof result.message.content !== 'string') {
                appendMessage('BOT', 'Pilot AI n’est pas disponible pour le moment.', 'client-assistant__message--error');
                return;
            }

            const botMessage = { role: 'assistant', content: result.message.content };
            const botMessageAdded = pushMemoryMessage(botMessage);
            persistHistory();
            const botArticle = botMessageAdded
                ? appendMessage(toVisualAuthor(botMessage.role), botMessage.content)
                : messages.querySelector('.client-assistant__message--bot:last-of-type');
            if (result.needsTechnician === true && botArticle) {
                appendDraftButton(botArticle, content, botMessage.content, shortHistory);
            }
        } catch {
            waiting.remove();
            appendMessage('BOT', 'Pilot AI n’est pas disponible pour le moment.', 'client-assistant__message--error');
        } finally {
            submit.disabled = false;
            input.focus();
        }
    });
})();
