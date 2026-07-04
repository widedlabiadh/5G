<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class WorkflowDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('decision', ChoiceType::class, [
                'label' => 'Décision',
                'choices' => $options['choices'],
                'placeholder' => 'Choisir une décision',
                'required' => true,
            ]);

        if (!empty($options['sites'])) {
            $builder->add('siteValidated', ChoiceType::class, [
                'label' => 'Site validé',
                'choices' => array_combine($options['sites'], $options['sites']),
                'placeholder' => 'Tous les sites',
                'required' => false,
            ]);
        }

        $builder
            ->add('comment', TextareaType::class, [
                'label' => 'Commentaire / Résultat',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('proofFile', FileType::class, [
                'label' => 'Pièce jointe / Preuve',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '8M',
                        'mimeTypes' => [
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/plain',
                        ],
                        'mimeTypesMessage' => 'Format de fichier non autorisé.',
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => [
                'OK' => 'ok',
                'NOK' => 'nok',
                'Bloquer' => 'blocked',
            ],
            'sites' => [],
        ]);

        $resolver->setAllowedTypes('choices', 'array');
        $resolver->setAllowedTypes('sites', 'array');
    }
}
