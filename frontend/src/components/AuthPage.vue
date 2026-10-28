<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  Check,
  ChevronDown,
  Eye,
  EyeOff,
  FileText,
  Home,
  LockKeyhole,
  Mail,
  MapPin,
  Phone,
  ShieldCheck,
  UserRound,
} from "lucide-vue-next";
import api from "../services/api";

const props = defineProps({ initialMode: { type: String, default: "login" } });
const emit = defineEmits(["go-home", "authenticated"]);
const mode = ref(props.initialMode);
const screen = ref("form");
const role = ref("student");
const showPassword = ref(false);
const submitting = ref(false);
const loadingInstitutions = ref(false);
const institutionError = ref("");
const errorMessage = ref("");
const successMessage = ref(
  new URLSearchParams(window.location.search).get("verified") === "1"
    ? "Adresse email vérifiée. Tu peux maintenant te connecter."
    : "",
);
const institutions = ref([]);
const institutionId = ref("");
const campusId = ref("");
const identityDocument = ref(null);
const token = ref(
  new URLSearchParams(window.location.search).get("token") || "",
);
const registrationStep = ref(1);
const isDesktop = ref(window.innerWidth >= 961);
const form = ref({
  firstName: "",
  lastName: "",
  phone: "",
  email: "",
  password: "",
  passwordConfirmation: "",
});

watch(
  form,
  (value) => {
    value.email = value.email.slice(0, 255);
    value.phone = value.phone.replace(/\D/g, "").slice(0, 10);
    value.password = value.password.slice(0, 128);
    value.passwordConfirmation = value.passwordConfirmation.slice(0, 128);
  },
  { deep: true },
);

const groups = computed(() => {
  const grouped = new Map();
  institutions.value.forEach((item) => {
    if (!grouped.has(item.category))
      grouped.set(item.category, { category: item.category, items: [] });
    grouped.get(item.category).items.push(item);
  });
  return [...grouped.values()];
});
const selectedInstitution = computed(() =>
  institutions.value.find(
    (item) => String(item.id) === String(institutionId.value),
  ),
);
const campuses = computed(() => selectedInstitution.value?.campuses || []);
const registrationDesktopFlow = computed(
  () => mode.value === "register" && isDesktop.value,
);

watch(institutionId, () => {
  campusId.value = campuses.value[0]?.id || "";
});

async function loadInstitutions() {
  loadingInstitutions.value = true;
  try {
    institutions.value = (await api.get("/institutions")).data.data || [];
  } catch {
    institutionError.value =
      "Les établissements sont momentanément indisponibles. Réessaie dans quelques instants.";
  } finally {
    loadingInstitutions.value = false;
  }
}
function switchPage(nextMode) {
  mode.value = nextMode;
  registrationStep.value = 1;
  screen.value = "form";
  errorMessage.value = "";
  institutionError.value = "";
  window.history.pushState(
    {},
    "",
    nextMode === "register" ? "/inscription" : "/connexion",
  );
  if (
    nextMode === "register" &&
    role.value === "student" &&
    !institutions.value.length
  )
    loadInstitutions();
}
function chooseRole(value) {
  role.value = value;
  registrationStep.value = 1;
  institutionId.value = "";
  campusId.value = "";
  identityDocument.value = null;
  institutionError.value = "";
  if (value === "student" && !institutions.value.length) loadInstitutions();
}

function updateViewport() {
  isDesktop.value = window.innerWidth >= 961;
}

function nextOwnerStep() {
  errorMessage.value = "";
  if (registrationStep.value === 1 && (!form.value.firstName.trim() || !form.value.lastName.trim())) {
    errorMessage.value = "Renseigne ton prénom et ton nom pour continuer.";
    return;
  }
  if (registrationStep.value === 2 && (!form.value.email.trim() || !form.value.phone.trim() || (role.value === "student" && !institutionId.value))) {
    errorMessage.value = role.value === "student"
      ? "Renseigne ton établissement, ton téléphone et une adresse email valide."
      : "Renseigne ton téléphone et une adresse email valide.";
    return;
  }
  if (registrationStep.value < 3) registrationStep.value += 1;
}

function previousOwnerStep() {
  errorMessage.value = "";
  if (registrationStep.value > 1) registrationStep.value -= 1;
}
function chooseFile(event) {
  identityDocument.value = event.target.files?.[0] || null;
  if (identityDocument.value?.size > 10 * 1024 * 1024) {
    errorMessage.value = "La pièce d’identité ne doit pas dépasser 10 Mo.";
    identityDocument.value = null;
    event.target.value = "";
  }
}
function forgotPassword() {
  screen.value = "forgot";
  errorMessage.value = "";
}
function backToLogin() {
  switchPage("login");
}

async function submit() {
  if (submitting.value) return;
  submitting.value = true;
  errorMessage.value = "";
  try {
    if (screen.value === "forgot") {
      await api.post("/auth/forgot-password", { email: form.value.email });
      screen.value = "sent";
      return;
    }
    if (screen.value === "reset") {
      await api.post("/auth/reset-password", {
        token: token.value,
        email: form.value.email,
        password: form.value.password,
        password_confirmation: form.value.passwordConfirmation,
      });
      errorMessage.value =
        "Mot de passe réinitialisé. Tu peux maintenant te connecter.";
      screen.value = "form";
      mode.value = "login";
      return;
    }
    let response;
    if (mode.value === "login") {
      response = await api.post("/auth/login", {
        email: form.value.email,
        password: form.value.password,
      });
    } else {
      const payload = new FormData();
      payload.append("first_name", form.value.firstName);
      payload.append("last_name", form.value.lastName);
      payload.append("phone", form.value.phone);
      payload.append("email", form.value.email);
      payload.append("password", form.value.password);
      payload.append("password_confirmation", form.value.passwordConfirmation);
      payload.append("role", role.value);
      if (role.value === "student")
        payload.append("institution_id", institutionId.value);
      if (identityDocument.value)
        payload.append("identity_document", identityDocument.value);
      response = await api.post("/auth/register", payload);
    }
    localStorage.setItem("findcasa_token", response.data.token);
    emit("authenticated", response.data.user);
  } catch (error) {
    if (error.response?.status === 429) {
      errorMessage.value =
        "Trop de demandes rapprochées. Attends une minute avant de réessayer.";
    } else if (error.response?.status === 422 && screen.value === "forgot") {
      errorMessage.value =
        "Impossible de traiter cette adresse pour le moment. Vérifie-la puis réessaie.";
    } else {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors).flat()[0] : null;
      errorMessage.value =
        firstError ||
        error.response?.data?.message ||
        "Une erreur est survenue. Vérifie les informations saisies.";
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  window.addEventListener("resize", updateViewport);
  if (mode.value === "register" && role.value === "student") loadInstitutions();
  if (token.value) screen.value = "reset";
});
onBeforeUnmount(() => window.removeEventListener("resize", updateViewport));
</script>

<template>
  <div class="auth-shell">
    <div
      v-if="institutionError && mode === 'register' && role === 'student'"
      class="auth-institution-alert auth-institution-floating"
    >
      {{ institutionError }}
    </div>
    <div class="auth-brand-panel">
      <div class="auth-brand-top">
        <button class="back-brand auth-back" @click="emit('go-home')">
          <span class="brand-mark"><Home :size="18" /></span
          ><span>find<span>casa</span></span></button
        ><span class="auth-location">Abomey-Calavi, Bénin</span>
      </div>
      <div class="auth-brand-content">
        <div class="eyebrow">
          <ShieldCheck :size="14" /> Une plateforme de confiance
        </div>
        <h1>Ton prochain<br /><em>chez-toi</em> commence ici.</h1>
        <p>
          Rejoins les étudiants qui trouvent leur logement plus simplement, plus
          vite et plus sereinement.
        </p>
      </div>
      <div class="auth-brand-footer">
        <span><Check :size="13" /> Annonces vérifiées</span
        ><span><Check :size="13" /> Données protégées</span>
      </div>
    </div>
    <div class="auth-form-panel">
      <div class="auth-form-wrap">
        <button class="auth-mobile-back" @click="emit('go-home')">
          <ArrowLeft :size="15" /> Retour à l’accueil
        </button>
        <div class="auth-heading">
          <span class="section-kicker">{{
            screen === "forgot" || screen === "sent"
              ? "Récupération du compte"
              : screen === "reset"
                ? "Nouveau mot de passe"
                : mode === "login"
                  ? "Ravi de te revoir"
                  : "Bienvenue chez FindCasa"
          }}</span>
          <h2>
            {{
              screen === "forgot"
                ? "Retrouve ton accès."
                : screen === "sent"
                  ? "Vérifie ta boîte mail."
                  : screen === "reset"
                    ? "Choisis un nouveau mot de passe."
                    : mode === "login"
                      ? "Connecte-toi à ton espace."
                      : "Crée ton espace en quelques clics."
            }}
          </h2>
          <p>
            {{
              screen === "forgot"
                ? "Saisis ton adresse email pour recevoir un lien sécurisé."
                : screen === "sent"
                  ? "Si un compte correspond, un lien vient d’être envoyé."
                  : screen === "reset"
                    ? "Ton nouveau mot de passe protégera ton compte FindCasa."
                    : mode === "login"
                      ? "Retrouve tes favoris et tes demandes de réservation."
                      : "Commence à trouver le logement qui te correspond."
            }}
          </p>
        </div>
        <div v-if="successMessage" class="auth-success-alert">
          {{ successMessage }}
        </div>
        <div v-if="errorMessage" class="auth-alert">{{ errorMessage }}</div>
        <div v-if="registrationDesktopFlow" class="owner-registration-stepper" aria-label="Étapes d’inscription">
          <span v-for="stepNumber in 3" :key="stepNumber" :class="{ active: registrationStep === stepNumber, done: registrationStep > stepNumber }"><i>{{ registrationStep > stepNumber ? '✓' : stepNumber }}</i><b>{{ stepNumber === 1 ? 'Identité' : stepNumber === 2 ? (role === 'student' ? 'Études et contact' : 'Contact') : 'Sécurité' }}</b></span>
        </div>
        <form
          v-if="screen !== 'sent'"
          class="auth-form"
          @submit.prevent="submit"
        >
          <template v-if="screen === 'forgot'"
            ><label
              ><span>Adresse email</span>
              <div class="auth-input">
                <Mail :size="15" /><input
                  v-model="form.email"
                  type="email"
                  required
                /></div></label
            ><button class="auth-submit" type="submit">
              Envoyer le lien <ArrowRight :size="16" /></button></template
          ><template v-else-if="screen === 'reset'"
            ><label
              ><span>Adresse email</span>
              <div class="auth-input">
                <Mail :size="15" /><input
                  v-model="form.email"
                  type="email"
                  required
                /></div></label
            ><label
              ><span>Nouveau mot de passe</span>
              <div class="auth-input">
                <LockKeyhole :size="15" /><input
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  minlength="8"
                  required
                /><button
                  type="button"
                  class="password-toggle"
                  @click="showPassword = !showPassword"
                >
                  <EyeOff v-if="showPassword" :size="15" /><Eye
                    v-else
                    :size="15"
                  />
                </button></div></label
            ><label
              ><span>Confirmer le mot de passe</span>
              <div class="auth-input">
                <LockKeyhole :size="15" /><input
                  v-model="form.passwordConfirmation"
                  type="password"
                  minlength="8"
                  required
                /></div></label
            ><button class="auth-submit" type="submit">
              Réinitialiser <ArrowRight :size="16" /></button></template
          ><template v-else
            ><div v-if="mode === 'register' && (!registrationDesktopFlow || registrationStep === 1)" class="auth-name-fields">
              <label
                ><span>Prénom</span>
                <div class="auth-input">
                  <UserRound :size="15" /><input
                    v-model="form.firstName"
                    type="text"
                    maxlength="100"
                    pattern="[A-Za-zÀ-ÖØ-öø-ÿ' -]+"
                    required
                  /></div></label
              ><label
                ><span>Nom</span>
                <div class="auth-input">
                  <input v-model="form.lastName" type="text" maxlength="100" pattern="[A-Za-zÀ-ÖØ-öø-ÿ' -]+" required /></div
              ></label>
            </div>
            <div v-if="mode === 'register' && (!registrationDesktopFlow || registrationStep === 1)" class="role-selector">
              <span>Je suis...</span>
              <div>
                <button
                  type="button"
                  :class="{ selected: role === 'student' }"
                  @click="chooseRole('student')"
                >
                  <strong>Étudiant(e)</strong
                  ><small>Je cherche un logement</small></button
                ><button
                  type="button"
                  :class="{ selected: role === 'owner' }"
                  @click="chooseRole('owner')"
                >
                  <strong>Propriétaire</strong
                  ><small>Je propose un logement</small>
                </button>
              </div>
            </div>
            <div
              v-if="mode === 'register' && role === 'student' && (!registrationDesktopFlow || registrationStep === 2)"
              class="education-fields"
            >
              <label
                ><span>Établissement</span>
                <div class="select-wrap">
                  <Building2 :size="15" /><select
                    v-model="institutionId"
                    required
                  >
                    <option value="" disabled>
                      {{
                        loadingInstitutions
                          ? "Chargement..."
                          : "Choisis ton établissement"
                      }}
                    </option>
                    <optgroup
                      v-for="group in groups"
                      :key="group.category"
                      :label="group.category"
                    >
                      <option
                        v-for="institution in group.items"
                        :key="institution.id"
                        :value="institution.id"
                      >
                        {{ institution.name }}
                      </option>
                    </optgroup></select
                  ><ChevronDown :size="14" /></div></label
              ><label
                ><span>Campus</span>
                <div class="select-wrap">
                  <MapPin :size="15" /><select
                    v-model="campusId"
                    :disabled="!selectedInstitution"
                    required
                  >
                    <option value="" disabled>Choisis ton campus</option>
                    <option
                      v-for="campus in campuses"
                      :key="campus.id"
                      :value="campus.id"
                    >
                      {{ campus.name }}
                    </option></select
                  ><ChevronDown :size="14" /></div
              ></label>
            </div>
            <label v-if="mode === 'register' && (!registrationDesktopFlow || registrationStep === 2)"
              ><span>Téléphone</span>
              <div class="auth-input">
                <Phone :size="15" /><input
                  v-model="form.phone"
                  type="tel"
                  inputmode="numeric"
                  pattern="[0-9]{10}"
                  minlength="10"
                  maxlength="10"
                  autocomplete="tel"
                  placeholder="Ex. 0157728807"
                  required
                /></div></label
            ><label v-if="!registrationDesktopFlow || registrationStep === 2"
              ><span>Adresse email</span>
              <div class="auth-input">
                <Mail :size="15" /><input
                  v-model="form.email"
                  type="email"
                  required
                /></div></label
            ><label v-if="mode === 'register' && role === 'owner' && (!registrationDesktopFlow || registrationStep === 3)"
              ><span>Pièce d’identité</span>
              <div class="auth-input">
                <FileText :size="15" /><input
                  type="file"
                  accept=".jpg,.jpeg,.png,.pdf"
                  required
                  @change="chooseFile"
                /></div></label
            ><label v-if="!registrationDesktopFlow || registrationStep === 3"
              ><span>Mot de passe</span>
              <div class="auth-input">
                <LockKeyhole :size="15" /><input
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  minlength="8"
                  required
                /><button
                  type="button"
                  class="password-toggle"
                  @click="showPassword = !showPassword"
                >
                  <EyeOff v-if="showPassword" :size="15" /><Eye
                    v-else
                    :size="15"
                  />
                </button></div></label
            ><label v-if="mode === 'register' && (!registrationDesktopFlow || registrationStep === 3)"
              ><span>Confirmer le mot de passe</span>
              <div class="auth-input">
                <LockKeyhole :size="15" /><input
                  v-model="form.passwordConfirmation"
                  type="password"
                  minlength="8"
                  required
                /></div></label
            ><button
              v-if="mode === 'login'"
              type="button"
              class="forgot-link"
              @click="forgotPassword"
            >
              Mot de passe oublié ?</button
            ><div v-if="registrationDesktopFlow && registrationStep < 3" class="owner-step-actions"><button v-if="registrationStep > 1" type="button" class="auth-step-back" @click="previousOwnerStep"><ArrowLeft :size="15" /> Retour</button><button type="button" class="auth-submit" @click="nextOwnerStep">Suivant <ArrowRight :size="16" /></button></div><div v-else-if="registrationDesktopFlow && registrationStep === 3" class="owner-step-actions"><button type="button" class="auth-step-back" @click="previousOwnerStep"><ArrowLeft :size="15" /> Retour</button><button class="auth-submit" type="submit" :disabled="submitting">
              {{
                submitting
                  ? "Traitement..."
                  : mode === "login"
                    ? "Se connecter"
                    : "Créer mon compte"
              }}
              <ArrowRight :size="16" /></button></div><button v-else class="auth-submit" type="submit" :disabled="submitting">
              {{
                submitting
                  ? "Traitement..."
                  : mode === "login"
                    ? "Se connecter"
                    : "Créer mon compte"
              }}
              <ArrowRight :size="16" /></button
          ></template>
        </form>
        <div v-if="screen === 'sent'" class="auth-success">
          <Check :size="20" /><strong>Consulte ta boîte mail</strong>
          <p>
            Le lien de réinitialisation est valable pendant une durée limitée.
          </p>
        </div>
        <button
          v-if="screen === 'forgot' || screen === 'reset' || screen === 'sent'"
          class="auth-back-link"
          type="button"
          @click="backToLogin"
        >
          <ArrowLeft :size="14" /> Retour à la connexion
        </button>
        <div v-if="screen === 'form'" class="auth-page-switch">
          <span>{{
            mode === "login"
              ? "Tu n’as pas encore de compte ?"
              : "Tu as déjà un compte ?"
          }}</span
          ><button
            type="button"
            @click="switchPage(mode === 'login' ? 'register' : 'login')"
          >
            {{ mode === "login" ? "Créer un compte" : "Se connecter" }}
            <ArrowRight :size="14" />
          </button>
        </div>
        <div
          v-if="screen === 'form' || screen === 'forgot' || screen === 'reset'"
          class="auth-legal"
        >
          En continuant, tu acceptes les
          <a href="/conditions">conditions d’utilisation</a> et la
          <a href="/confidentialite">politique de confidentialité</a>.
        </div>
        <div class="auth-secure">
          <ShieldCheck :size="14" /><span>Tes données sont chiffrées.</span>
        </div>
      </div>
    </div>
  </div>
</template>
