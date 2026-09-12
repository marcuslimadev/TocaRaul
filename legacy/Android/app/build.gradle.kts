plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
}

val uploadStore = providers.environmentVariable("TOCARAUL_UPLOAD_STORE_FILE")
val uploadStorePassword = providers.environmentVariable("TOCARAUL_UPLOAD_STORE_PASSWORD")
val uploadKeyAlias = providers.environmentVariable("TOCARAUL_UPLOAD_KEY_ALIAS")
val uploadKeyPassword = providers.environmentVariable("TOCARAUL_UPLOAD_KEY_PASSWORD")
val hasUploadSigning = listOf(uploadStore, uploadStorePassword, uploadKeyAlias, uploadKeyPassword)
    .all { !it.orNull.isNullOrBlank() }

android {
    namespace = "com.socimob.tocaraul"
    compileSdk {
        version = release(37)
    }

    defaultConfig {
        applicationId = "com.socimob.tocaraul"
        minSdk = 23
        targetSdk = 37
        versionCode = 1
        versionName = "1.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        val apiBaseUrl = providers.gradleProperty("tocaraulApiBaseUrl")
            .getOrElse("https://tocaraul.lojadaesquina.store").trimEnd('/')
        require((apiBaseUrl.startsWith("https://") || apiBaseUrl == "http://127.0.0.1:8787") && !apiBaseUrl.contains('"')) {
            "tocaraulApiBaseUrl deve ser HTTPS ou o servidor local de homologação"
        }
        buildConfigField("String", "TOCARAUL_API_BASE_URL", "\"$apiBaseUrl\"")

    }
    signingConfigs {
        if (hasUploadSigning) {
            create("upload") {
                storeFile = file(uploadStore.get())
                storePassword = uploadStorePassword.get()
                keyAlias = uploadKeyAlias.get()
                keyPassword = uploadKeyPassword.get()
            }
        }
    }
    buildTypes {
        debug {
            if (providers.gradleProperty("tocaraulE2e").orNull == "true") {
                applicationIdSuffix = ".e2e"
                versionNameSuffix = "-e2e"
            }
        }
        release {
            if (hasUploadSigning) signingConfig = signingConfigs.getByName("upload")
            optimization {
                enable = false
            }
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }
    buildFeatures {
        compose = true
        buildConfig = true
    }

}

gradle.taskGraph.whenReady {
    if (allTasks.any { it.name.contains("Release", ignoreCase = true) }) {
        require(hasUploadSigning || providers.gradleProperty("tocaraulAllowUnsigned").orNull == "true") {
            "Configure as quatro variáveis TOCARAUL_UPLOAD_* de assinatura. Para validação local sem assinatura use -PtocaraulAllowUnsigned=true."
        }
        require(!providers.gradleProperty("tocaraulApiBaseUrl").orNull.orEmpty().startsWith("http://")) {
            "Release não pode usar HTTP local"
        }
    }
}

dependencies {
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.appcompat)
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.tv.foundation)
    implementation(libs.androidx.tv.material)
    debugImplementation(libs.androidx.compose.ui.tooling)
}
