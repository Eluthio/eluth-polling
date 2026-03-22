<template>
    <div class="poll-wrap" ref="wrapRef">
        <!-- Toolbar button -->
        <button
            class="poll-btn"
            :class="{ active: open, 'poll-btn--live': poll?.is_active }"
            @click="toggle"
            title="Polls"
        >
            📊
            <span v-if="poll?.is_active" class="poll-live-badge">LIVE</span>
        </button>

        <!-- Panel -->
        <Teleport to="body">
        <div v-if="open" class="poll-panel" :style="panelStyle" @click.stop>
            <div class="poll-panel-header">
                <span class="poll-panel-title">📊 Poll</span>
                <button class="poll-close" @click="open = false">✕</button>
            </div>

            <!-- ── ACTIVE / RECENTLY CLOSED POLL ──────────────────────── -->
            <div v-if="poll" class="poll-active">
                <!-- Status bar -->
                <div class="poll-status-bar">
                    <span class="poll-status" :class="poll.is_active ? 'poll-status--live' : 'poll-status--closed'">
                        {{ poll.is_active ? 'LIVE' : 'ENDED' }}
                    </span>
                    <span v-if="poll.is_active && poll.closes_at" class="poll-timer">
                        {{ timeRemaining }}
                    </span>
                    <span v-else-if="poll.is_active" class="poll-timer">No time limit</span>

                    <!-- Close button for creator / mods -->
                    <button
                        v-if="poll.is_active && canClose"
                        class="poll-close-btn"
                        @click="closePoll"
                        :disabled="closing"
                    >{{ closing ? 'Closing…' : 'End poll' }}</button>
                </div>

                <!-- Question -->
                <div class="poll-question">{{ poll.question }}</div>

                <!-- Options — vote buttons if not yet voted AND poll active -->
                <div class="poll-options">
                    <button
                        v-for="opt in poll.options"
                        :key="opt.id"
                        class="poll-option"
                        :class="{
                            'poll-option--voted':  poll.my_vote === opt.id,
                            'poll-option--winner': !poll.is_active && isWinner(opt),
                            'poll-option--result': poll.my_vote !== null || !poll.is_active,
                        }"
                        @click="vote(opt)"
                        :disabled="!poll.is_active || voting !== null"
                    >
                        <div class="poll-option-top">
                            <span class="poll-option-label">{{ opt.label }}</span>
                            <span class="poll-option-meta">
                                <span v-if="poll.my_vote === opt.id" class="poll-option-check">✓</span>
                                <span class="poll-option-pct">{{ opt.percent }}%</span>
                            </span>
                        </div>
                        <!-- Progress bar (shown after voting or when closed) -->
                        <div
                            v-if="poll.my_vote !== null || !poll.is_active"
                            class="poll-option-bar-track"
                        >
                            <div
                                class="poll-option-bar"
                                :style="{ width: opt.percent + '%' }"
                                :class="{ 'poll-option-bar--winner': !poll.is_active && isWinner(opt) }"
                            />
                        </div>
                    </button>
                </div>

                <!-- Total votes -->
                <div class="poll-footer">
                    {{ poll.total_votes }} {{ poll.total_votes === 1 ? 'vote' : 'votes' }}
                    <span v-if="poll.my_vote !== null && poll.is_active" class="poll-voted-note">
                        — Tap an option to change your vote
                    </span>
                </div>
            </div>

            <!-- ── NO ACTIVE POLL ──────────────────────────────────────── -->
            <div v-else-if="!loading" class="poll-empty">
                <span v-if="!canCreate">No active poll.</span>
            </div>

            <!-- ── CREATE FORM (canCreate, no active poll) ─────────────── -->
            <form v-if="canCreate && (!poll || !poll.is_active)" class="poll-create" @submit.prevent="createPoll">
                <div class="poll-section-label">Create a poll</div>

                <input
                    v-model="form.question"
                    class="poll-input"
                    type="text"
                    placeholder="Ask a question…"
                    maxlength="300"
                    required
                />

                <div class="poll-options-editor">
                    <div
                        v-for="(opt, i) in form.options"
                        :key="i"
                        class="poll-option-row"
                    >
                        <input
                            v-model="form.options[i]"
                            class="poll-input poll-input--option"
                            type="text"
                            :placeholder="`Option ${i + 1}`"
                            maxlength="100"
                            required
                        />
                        <button
                            v-if="form.options.length > 2"
                            type="button"
                            class="poll-option-remove"
                            @click="form.options.splice(i, 1)"
                            title="Remove"
                        >✕</button>
                    </div>
                    <button
                        v-if="form.options.length < 10"
                        type="button"
                        class="poll-add-option"
                        @click="form.options.push('')"
                    >+ Add option</button>
                </div>

                <div class="poll-duration-row">
                    <span class="poll-duration-label">Duration</span>
                    <div class="poll-duration-pills">
                        <button
                            v-for="d in DURATIONS"
                            :key="d.value"
                            type="button"
                            class="poll-pill"
                            :class="{ active: form.duration === d.value }"
                            @click="form.duration = d.value"
                        >{{ d.label }}</button>
                    </div>
                </div>

                <div v-if="createError" class="poll-error">{{ createError }}</div>

                <button class="poll-create-btn" type="submit" :disabled="creating">
                    {{ creating ? 'Creating…' : 'Start Poll' }}
                </button>
            </form>
        </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
    settings:      { type: Object, default: () => ({}) },
    authToken:     { type: String, default: '' },
    apiBase:       { type: String, default: '' },
    channelId:     { type: String, default: '' },
    currentMember: { type: Object, default: null },
})

const DURATIONS = [
    { label: 'No limit', value: null },
    { label: '1 min',   value: 1 },
    { label: '2 min',   value: 2 },
    { label: '5 min',   value: 5 },
    { label: '10 min',  value: 10 },
    { label: '30 min',  value: 30 },
]

const open      = ref(false)
const loading   = ref(false)
const poll      = ref(null)
const voting    = ref(null)
const closing   = ref(false)
const creating  = ref(false)
const createError = ref('')
const wrapRef   = ref(null)
const panelStyle = ref({})

const form = ref({
    question: '',
    options: ['', ''],
    duration: null,
})

let pollTimer     = null
let countdownInt  = null
let echoChannel   = null
const timeRemaining = ref('')

// ── Permissions ────────────────────────────────────────────────────────────

const canCreate = computed(() => {
    if (!props.currentMember) return false
    return props.currentMember.isSuperAdmin || props.currentMember.isAdmin ||
           (props.currentMember.can?.('poll.create') ?? false)
})

const canClose = computed(() => {
    if (!props.currentMember || !poll.value) return false
    if (props.currentMember.isSuperAdmin || props.currentMember.isAdmin) return true
    if (props.currentMember.can?.('poll.moderate')) return true
    return poll.value.created_by_id === props.currentMember.id
})

// ── Helpers ────────────────────────────────────────────────────────────────

function base() { return props.apiBase.replace(/\/$/, '') }

function headers() {
    return {
        Authorization:  'Bearer ' + props.authToken,
        'Content-Type': 'application/json',
        Accept:         'application/json',
    }
}

function isWinner(opt) {
    if (!poll.value || poll.value.total_votes === 0) return false
    const max = Math.max(...poll.value.options.map(o => o.votes))
    return opt.votes === max && max > 0
}

function updateCountdown() {
    if (!poll.value?.is_active || !poll.value.closes_at) {
        timeRemaining.value = ''
        return
    }
    const diff = Math.max(0, new Date(poll.value.closes_at).getTime() - Date.now())
    if (diff === 0) {
        timeRemaining.value = 'Ending…'
        fetchPoll()
        return
    }
    const m = Math.floor(diff / 60000)
    const s = Math.floor((diff % 60000) / 1000)
    timeRemaining.value = m > 0 ? `${m}m ${s.toString().padStart(2, '0')}s` : `${s}s`
}

// ── Data ───────────────────────────────────────────────────────────────────

async function fetchPoll() {
    if (!props.channelId) return
    loading.value = true
    try {
        const res = await fetch(`${base()}/api/plugins/polls/active?channel_id=${props.channelId}`, {
            headers: { Authorization: 'Bearer ' + props.authToken, Accept: 'application/json' },
        })
        if (res.ok) {
            const data = await res.json()
            poll.value = data.poll
        }
    } finally {
        loading.value = false
    }
}

// ── Actions ────────────────────────────────────────────────────────────────

async function vote(opt) {
    if (!poll.value?.is_active || voting.value !== null) return
    voting.value = opt.id
    try {
        const res = await fetch(`${base()}/api/plugins/polls/${poll.value.id}/vote`, {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({ option_id: opt.id }),
        })
        if (res.ok) {
            const data = await res.json()
            poll.value = data.poll
        }
    } finally {
        voting.value = null
    }
}

async function closePoll() {
    if (!poll.value || closing.value) return
    closing.value = true
    try {
        const res = await fetch(`${base()}/api/plugins/polls/${poll.value.id}/close`, {
            method: 'POST',
            headers: headers(),
        })
        if (res.ok) {
            const data = await res.json()
            poll.value = data.poll
        }
    } finally {
        closing.value = false
    }
}

async function createPoll() {
    createError.value = ''
    const options = form.value.options.map(o => o.trim()).filter(Boolean)
    if (options.length < 2) {
        createError.value = 'At least 2 options required.'
        return
    }
    creating.value = true
    try {
        const res = await fetch(`${base()}/api/plugins/polls`, {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({
                channel_id:       props.channelId,
                question:         form.value.question.trim(),
                options,
                duration_minutes: form.value.duration,
            }),
        })
        if (!res.ok) {
            const data = await res.json().catch(() => ({}))
            createError.value = data.message || `Error ${res.status}.`
            return
        }
        const data = await res.json()
        poll.value = data.poll
        // Reset form
        form.value = { question: '', options: ['', ''], duration: null }
    } catch {
        createError.value = 'Network error.'
    } finally {
        creating.value = false
    }
}

// ── WebSocket ──────────────────────────────────────────────────────────────

function subscribeToPolls() {
    if (!window._echo || !props.channelId) return
    try {
        echoChannel = window._echo.channel('polls.' + props.channelId)
        echoChannel.listen('.poll.updated', ({ poll: updated }) => {
            poll.value = updated
        })
    } catch {}
}

function unsubscribeFromPolls() {
    if (echoChannel) {
        try { window._echo?.leaveChannel?.('polls.' + props.channelId) } catch {}
        echoChannel = null
    }
}

// ── Panel ──────────────────────────────────────────────────────────────────

function positionPanel() {
    const rect = wrapRef.value?.getBoundingClientRect()
    if (!rect) return
    panelStyle.value = {
        left:   Math.max(8, Math.min(rect.left, window.innerWidth - 320)) + 'px',
        bottom: (window.innerHeight - rect.top + 8) + 'px',
    }
}

function toggle() {
    open.value = !open.value
    if (open.value) {
        positionPanel()
        fetchPoll()
        pollTimer = setInterval(fetchPoll, 10000)
        countdownInt = setInterval(updateCountdown, 500)
    } else {
        clearInterval(pollTimer)
        clearInterval(countdownInt)
    }
}

function onClickOutside(e) {
    if (open.value && !wrapRef.value?.contains(e.target) && !document.querySelector('.poll-panel')?.contains(e.target)) {
        open.value = false
        clearInterval(pollTimer)
        clearInterval(countdownInt)
    }
}

onMounted(() => {
    document.addEventListener('click', onClickOutside)
    subscribeToPolls()
    fetchPoll()
})

onUnmounted(() => {
    document.removeEventListener('click', onClickOutside)
    clearInterval(pollTimer)
    clearInterval(countdownInt)
    unsubscribeFromPolls()
})
</script>

<style scoped>
.poll-wrap { position: relative; display: inline-flex; align-items: center; }

.poll-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    font-size: 17px;
    line-height: 1;
    color: rgba(255,255,255,.7);
    transition: background .15s;
    position: relative;
    display: flex;
    align-items: center;
    gap: 3px;
}
.poll-btn:hover, .poll-btn.active { background: rgba(99,102,241,.12); }
.poll-btn--live { color: #fff; }

.poll-live-badge {
    font-size: 9px;
    font-weight: 800;
    background: #ef4444;
    color: #fff;
    border-radius: 6px;
    padding: 1px 4px;
    line-height: 1.4;
    letter-spacing: .04em;
    animation: pulse-badge 2s ease-in-out infinite;
}
@keyframes pulse-badge {
    0%, 100% { opacity: 1; }
    50% { opacity: .6; }
}

.poll-panel {
    position: fixed;
    width: 300px;
    max-height: 560px;
    background: #1a1d26;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 12px;
    box-shadow: 0 16px 48px rgba(0,0,0,.6);
    display: flex;
    flex-direction: column;
    z-index: 9999;
    overflow: hidden;
}

.poll-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px 10px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    flex-shrink: 0;
}
.poll-panel-title { font-size: 14px; font-weight: 700; color: rgba(255,255,255,.9); }
.poll-close { background: none; border: none; cursor: pointer; color: rgba(255,255,255,.4); font-size: 14px; padding: 2px 6px; border-radius: 4px; }
.poll-close:hover { color: rgba(255,255,255,.8); background: rgba(255,255,255,.06); }

/* ── Active poll ── */
.poll-active {
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    overflow-y: auto;
    flex: 1;
}

.poll-status-bar {
    display: flex;
    align-items: center;
    gap: 8px;
}
.poll-status {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .06em;
    padding: 2px 7px;
    border-radius: 10px;
}
.poll-status--live { background: rgba(239,68,68,.2); color: #f87171; }
.poll-status--closed { background: rgba(156,163,175,.15); color: #9ca3af; }

.poll-timer {
    font-size: 12px;
    color: rgba(255,255,255,.45);
    font-variant-numeric: tabular-nums;
    flex: 1;
}

.poll-close-btn {
    background: none;
    border: 1px solid rgba(248,113,113,.3);
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 11px;
    color: #f87171;
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
}
.poll-close-btn:hover { background: rgba(248,113,113,.1); }
.poll-close-btn:disabled { opacity: .5; cursor: default; }

.poll-question {
    font-size: 15px;
    font-weight: 700;
    color: rgba(255,255,255,.92);
    line-height: 1.35;
}

.poll-options {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.poll-option {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 8px;
    padding: 8px 10px;
    cursor: pointer;
    text-align: left;
    transition: background .12s, border-color .12s;
    width: 100%;
    overflow: hidden;
}
.poll-option:hover:not(:disabled) { background: rgba(99,102,241,.12); border-color: rgba(99,102,241,.3); }
.poll-option--voted { background: rgba(99,102,241,.15); border-color: rgba(99,102,241,.5); }
.poll-option--winner { border-color: rgba(251,191,36,.5); background: rgba(251,191,36,.08); }
.poll-option:disabled { cursor: default; }

.poll-option-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 4px;
}
.poll-option--result .poll-option-top { margin-bottom: 6px; }

.poll-option-label {
    font-size: 13px;
    color: rgba(255,255,255,.85);
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.poll-option--winner .poll-option-label { color: #fcd34d; }

.poll-option-meta {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}
.poll-option-check { font-size: 11px; color: #818cf8; font-weight: 700; }
.poll-option-pct   { font-size: 12px; color: rgba(255,255,255,.5); font-variant-numeric: tabular-nums; }
.poll-option--winner .poll-option-pct { color: #fcd34d; font-weight: 700; }

.poll-option-bar-track {
    height: 4px;
    background: rgba(255,255,255,.06);
    border-radius: 4px;
    overflow: hidden;
}
.poll-option-bar {
    height: 100%;
    background: #6366f1;
    border-radius: 4px;
    transition: width .4s ease;
}
.poll-option-bar--winner { background: #f59e0b; }

.poll-footer {
    font-size: 11px;
    color: rgba(255,255,255,.3);
}
.poll-voted-note { color: rgba(255,255,255,.2); }

.poll-empty {
    padding: 20px 14px 8px;
    font-size: 13px;
    color: rgba(255,255,255,.3);
}

/* ── Create form ── */
.poll-create {
    border-top: 1px solid rgba(255,255,255,.06);
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex-shrink: 0;
    overflow-y: auto;
    max-height: 340px;
}

.poll-section-label {
    font-size: 11px;
    font-weight: 700;
    color: rgba(255,255,255,.35);
    text-transform: uppercase;
    letter-spacing: .06em;
}

.poll-input {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 7px;
    padding: 7px 10px;
    font-size: 13px;
    color: rgba(255,255,255,.85);
    width: 100%;
    box-sizing: border-box;
}
.poll-input:focus { outline: none; border-color: rgba(99,102,241,.5); }
.poll-input::placeholder { color: rgba(255,255,255,.25); }
.poll-input--option { flex: 1; }

.poll-options-editor {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.poll-option-row {
    display: flex;
    gap: 5px;
    align-items: center;
}

.poll-option-remove {
    background: none;
    border: none;
    cursor: pointer;
    color: rgba(255,255,255,.25);
    font-size: 11px;
    padding: 4px 6px;
    border-radius: 4px;
    flex-shrink: 0;
}
.poll-option-remove:hover { color: #f87171; background: rgba(248,113,113,.1); }

.poll-add-option {
    background: none;
    border: 1px dashed rgba(255,255,255,.12);
    border-radius: 7px;
    padding: 6px 10px;
    font-size: 12px;
    color: rgba(255,255,255,.35);
    cursor: pointer;
    text-align: left;
    transition: all .15s;
    width: 100%;
}
.poll-add-option:hover { border-color: rgba(99,102,241,.4); color: #818cf8; }

.poll-duration-row {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.poll-duration-label {
    font-size: 11px;
    color: rgba(255,255,255,.35);
    font-weight: 600;
}
.poll-duration-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}
.poll-pill {
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    padding: 3px 10px;
    font-size: 12px;
    color: rgba(255,255,255,.5);
    cursor: pointer;
    transition: all .15s;
}
.poll-pill:hover { background: rgba(99,102,241,.1); border-color: rgba(99,102,241,.3); color: #818cf8; }
.poll-pill.active { background: rgba(99,102,241,.2); border-color: #6366f1; color: #a5b4fc; font-weight: 600; }

.poll-error {
    font-size: 12px;
    color: #f87171;
}

.poll-create-btn {
    background: rgba(99,102,241,.2);
    border: 1px solid rgba(99,102,241,.4);
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 700;
    color: #a5b4fc;
    cursor: pointer;
    transition: all .15s;
    width: 100%;
}
.poll-create-btn:hover { background: rgba(99,102,241,.3); }
.poll-create-btn:disabled { opacity: .5; cursor: default; }
</style>
