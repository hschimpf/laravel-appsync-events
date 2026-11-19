/**
 * CDK v2 stack: AppSync Event API for hds-solutions/laravel-appsync-events.
 *
 * Uses L1 constructs only (aws-appsync.CfnApi / CfnChannelNamespace), so it
 * works on any CDK v2 release that includes the Event API CFN resources.
 *
 * Validated: `tsc --noEmit` against aws-cdk-lib 2.277.0, TypeScript 7.0.2 strict.
 * NOT deployed to an AWS account.
 */
import { Stack, StackProps, CfnOutput } from 'aws-cdk-lib';
import type { Construct } from 'constructs';
import * as appsync from 'aws-cdk-lib/aws-appsync';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as lambda from 'aws-cdk-lib/aws-lambda';

export interface EventApiStackProps extends StackProps {
    /** Lambda function running the channel authorizer (must already exist). */
    readonly authorizer: lambda.IFunction;
    /** IAM principal allowed to publish events (your application's role). */
    readonly publisher: iam.IGrantable;
    /**
     * AppSync channel namespaces to create — the authorization boundary
     * channel names map onto (`private-` names under `private`, everything
     * else under `public`). The namespace named `private` subscribes via
     * the Lambda authorizer only; the others also accept API_KEY subscribes
     * when `publicChannels` is enabled. All publish over IAM.
     */
    readonly namespaces?: string[];
    /** Enable API_KEY auth so anonymous/public channels can connect. */
    readonly publicChannels?: boolean;
}

export class EventApiStack extends Stack {
    /** Value for APPSYNC_HTTP_ENDPOINT. */
    public readonly httpEndpoint: string;
    /** Value for APPSYNC_REALTIME_ENDPOINT. */
    public readonly realtimeEndpoint: string;

    public constructor(scope: Construct, id: string, props: EventApiStackProps) {
        super(scope, id, props);

        const authModes = (types: string[]): appsync.CfnApi.AuthModeProperty[] =>
            types.map((authType) => ({ authType }));

        const api = new appsync.CfnApi(this, 'EventApi', {
            name: `${this.stackName}-events`,
            eventConfig: {
                authProviders: [
                    {
                        authType: 'AWS_LAMBDA',
                        lambdaAuthorizerConfig: {
                            authorizerUri: props.authorizer.functionArn,
                            authorizerResultTtlInSeconds: 300,
                        },
                    },
                    { authType: 'AWS_IAM' },
                    ...(props.publicChannels ? [{ authType: 'API_KEY' }] : []),
                ],
                connectionAuthModes: authModes(
                    props.publicChannels ? ['AWS_LAMBDA', 'API_KEY'] : ['AWS_LAMBDA'],
                ),
                defaultPublishAuthModes: authModes(['AWS_IAM']),
                defaultSubscribeAuthModes: authModes(
                    props.publicChannels ? ['AWS_LAMBDA', 'API_KEY'] : ['AWS_LAMBDA'],
                ),
            },
        });

        const namespaces = props.namespaces ?? ['public', 'private'];

        for (const name of namespaces) {
            new appsync.CfnChannelNamespace(this, `Namespace-${name}`, {
                apiId: api.attrApiId,
                name,
                publishAuthModes: authModes(['AWS_IAM']),
                subscribeAuthModes: authModes(
                    name === 'private'
                        ? ['AWS_LAMBDA']
                        : props.publicChannels ? ['AWS_LAMBDA', 'API_KEY'] : ['AWS_LAMBDA'],
                ),
            });
        }

        props.authorizer.addPermission('AppSyncInvoke', {
            principal: new iam.ServicePrincipal('appsync.amazonaws.com'),
            sourceArn: api.attrApiArn,
        });

        // appsync:EventPublish scoped to this API's channel namespaces.
        props.publisher.grantPrincipal.addToPrincipalPolicy(
            new iam.PolicyStatement({
                actions: ['appsync:EventPublish'],
                resources: namespaces.map(
                    (ns) => `${api.attrApiArn}/channelNamespace/${ns}`,
                ),
            }),
        );

        this.httpEndpoint = `https://${api.attrDnsHttp}/event`;
        this.realtimeEndpoint = `wss://${api.attrDnsRealtime}/event/realtime`;

        new CfnOutput(this, 'HttpEndpoint', { value: this.httpEndpoint });
        new CfnOutput(this, 'RealtimeEndpoint', { value: this.realtimeEndpoint });
    }
}
