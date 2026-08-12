plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val signingStoreFile =
    providers.gradleProperty("EVERSHELF_SIGNING_STORE_FILE").orNull
        ?: System.getenv("EVERSHELF_SIGNING_STORE_FILE")
val signingStorePassword =
    providers.gradleProperty("EVERSHELF_SIGNING_STORE_PASSWORD").orNull
        ?: System.getenv("EVERSHELF_SIGNING_STORE_PASSWORD")
val signingKeyAlias =
    providers.gradleProperty("EVERSHELF_SIGNING_KEY_ALIAS").orNull
        ?: System.getenv("EVERSHELF_SIGNING_KEY_ALIAS")
val signingKeyPassword =
    providers.gradleProperty("EVERSHELF_SIGNING_KEY_PASSWORD").orNull
        ?: System.getenv("EVERSHELF_SIGNING_KEY_PASSWORD")
val releaseSigningConfigured = listOf(
    signingStoreFile,
    signingStorePassword,
    signingKeyAlias,
    signingKeyPassword,
).all { !it.isNullOrBlank() }

if (
    gradle.startParameter.taskNames.any {
        it.contains("release", ignoreCase = true)
    } && !releaseSigningConfigured
) {
    throw GradleException(
        "Release signing requires the EVERSHELF_SIGNING_* Gradle properties " +
            "or environment variables."
    )
}

gradle.taskGraph.whenReady { graph ->
    if (
        !releaseSigningConfigured
        && graph.allTasks.any {
            it.name.contains("release", ignoreCase = true)
        }
    ) {
        throw GradleException(
            "Release signing requires the EVERSHELF_SIGNING_* Gradle " +
                "properties or environment variables."
        )
    }
}

android {
    namespace = "it.dadaloop.evershelf.kiosk"
    compileSdk = 35

    defaultConfig {
        applicationId = "it.dadaloop.evershelf.kiosk"
        minSdk = 24
        targetSdk = 35
        versionCode = 20
        versionName = "1.7.19"
    }

    signingConfigs {
        if (releaseSigningConfigured) {
            create("externalRelease") {
                storeFile = file(signingStoreFile!!)
                storePassword = signingStorePassword
                keyAlias = signingKeyAlias
                keyPassword = signingKeyPassword
            }
        }
    }

    buildTypes {
        debug {
            // Use Android's standard per-machine debug signing key.
        }
        release {
            isMinifyEnabled = false
            if (releaseSigningConfigured) {
                signingConfig = signingConfigs.getByName("externalRelease")
            }
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt")
            )
        }
    }

    buildFeatures {
        viewBinding = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_1_8
        targetCompatibility = JavaVersion.VERSION_1_8
    }
    kotlinOptions {
        jvmTarget = "1.8"
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.12.0")
    implementation("androidx.appcompat:appcompat:1.6.1")
    implementation("com.google.android.material:material:1.11.0")
    implementation("androidx.constraintlayout:constraintlayout:2.1.4")
    implementation("androidx.webkit:webkit:1.10.0")
    implementation("androidx.recyclerview:recyclerview:1.3.2")
    implementation("org.java-websocket:Java-WebSocket:1.5.5")
}
