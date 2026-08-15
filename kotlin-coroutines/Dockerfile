FROM gradle:8.9-jdk21 AS build

WORKDIR /app
COPY build.gradle.kts settings.gradle.kts ./
COPY src ./src

RUN gradle --no-daemon installDist

FROM eclipse-temurin:21-jre-jammy

RUN apt-get update && apt-get install -y --no-install-recommends wget \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=build /app/build/install/risk-analysis-kotlin ./

ENV PORT=9502

EXPOSE 9502

HEALTHCHECK --interval=5s --timeout=3s --retries=5 \
    CMD wget -qO- http://127.0.0.1:9502/health || exit 1

ENTRYPOINT ["./bin/risk-analysis-kotlin"]
